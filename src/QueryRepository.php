<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\QueryRepository\Log\Context\CachePolicyContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeResultContext;
use BEAR\QueryRepository\Log\Context\ManualStoreContext;
use BEAR\QueryRepository\Log\Context\ManualStoreResultContext;
use BEAR\QueryRepository\Log\Context\PreWriteCleanupContext;
use BEAR\QueryRepository\Log\Context\PurgeContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use ReflectionClass;

use function is_array;
use function max;
use function serialize;
use function sprintf;
use function strtotime;
use function time;

/** @internal */
final readonly class QueryRepository implements QueryRepositoryInterface
{
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private HeaderSetter $headerSetter,
        private ResourceStorageInterface $storage,
        private Expiry $expiry,
        private CacheDependencyCollector $dependencyCollector,
        private CacheTags $cacheTags,
        private DeclaredCacheControl $declaredCacheControl = new DeclaredCacheControl(),
        private ResourceBodyEvaluator $evaluateBody = new ResourceBodyEvaluator(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function put(ResourceObject $ro)
    {
        // A top-level put is a direct (non-AOP) cache write: root it in a manual_store
        // scope so it stands out from a write the framework drove, and so its outcome has
        // a close to ride on. A put nested inside a request GET or a write command keeps
        // emitting its save events under that scope, unchanged.
        if ($this->logger instanceof TopLevelAwareInterface && $this->logger->isTopLevel()) {
            $openId = $this->logger->open(new ManualStoreContext((string) $ro->uri));
            $stored = false;
            try {
                return $stored = $this->doPut($ro);
            } finally {
                $this->logger->close(new ManualStoreResultContext($stored), $openId);
            }
        }

        return $this->doPut($ro);
    }

    private function doPut(ResourceObject $ro): bool
    {
        if ($ro->code === 200) {
            $this->setCacheDependency($ro);
        }

        $cacheable = $this->getCacheableAnnotation($ro);
        $httpCache = $this->getHttpCacheAnnotation($ro);
        $storesView = $cacheable instanceof Cacheable && $cacheable->type === 'view';
        if ($storesView) {
            // Rendering is the write itself here. For a value entry it is not: a resource with no
            // renderer in this context - an app resource under an html renderer - would throw, the
            // interceptor would degrade it to a warning, and the entry would never be stored.
            $ro->toString();
        }

        $ttl = $this->lifetime($ro, $cacheable);

        $unstorable = $this->declaredCacheControl->reason($ro);
        if ($unstorable === null) {
            // Every body this store keeps must serialize: a refusal surfaces here, before the
            // cleanup below can purge the entry and its dependents for a write that cannot happen
            serialize(($this->evaluateBody)($ro->body));
        }

        ($this->headerSetter)($ro, $this->clientCacheControl($cacheable, $ttl), $httpCache);
        $this->cleanUpBeforeWrite($ro, $unstorable);

        if ($unstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable));

            return false;
        }

        return $storesView ? $this->storage->saveView($ro, $ttl) : $this->storage->saveValue($ro, $ttl);
    }

    /** The seconds a #[Cacheable] entry lives, recorded with the declaration that decided them */
    private function lifetime(ResourceObject $ro, Cacheable|null $cacheable): int
    {
        $ttl = $this->getExpiryTime($ro, $cacheable);
        if ($cacheable instanceof Cacheable) {
            $this->logger->event($this->cachePolicy($ro, $cacheable, $ttl));
        }

        return $ttl;
    }

    /**
     * An entry without a lifetime lives until a write invalidates it: max-age=0 makes a browser
     * revalidate it, while an s-maxage still governs a shared cache
     */
    private function clientCacheControl(Cacheable|null $cacheable, int $ttl): string|null
    {
        if ($cacheable instanceof Cacheable && $cacheable->expiryAt === '' && $cacheable->expirySecond === 0 && $cacheable->expiry === 'never') {
            return 'max-age=0';
        }

        return $this->headerSetter->maxAge($ttl);
    }

    private function cleanUpBeforeWrite(ResourceObject $ro, string|null $skipReason): void
    {
        if ($skipReason !== null && $this->storage->get($ro->uri) === null) {
            return;
        }

        // The marker records that this invalidation clears the entry about to be rewritten,
        // so readers never have to infer cleanup-vs-invalidation from tag correlation.
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->storage->invalidateTags([$this->cacheTags->uriTag($ro->uri)]);
    }

    /**
     * The declaration that decided the lifetime, as declared.
     *
     * The precedence is the one {@see self::getExpiryTime()} applies: an expiry field in the body
     * wins, then an explicit second count, then the preset. Only the winner is recorded, so a
     * reader never has to re-derive which of the three was in force.
     */
    private function cachePolicy(ResourceObject $ro, Cacheable $cacheable, int $ttl): CachePolicyContext
    {
        $uri = (string) $ro->uri;
        if ($cacheable->expiryAt !== '') {
            return new CachePolicyContext($uri, null, null, $cacheable->expiryAt, $ttl);
        }

        if ($cacheable->expirySecond !== 0) {
            return new CachePolicyContext($uri, null, $cacheable->expirySecond, null, $ttl);
        }

        return new CachePolicyContext($uri, $cacheable->expiry, null, null, $ttl);
    }

    private function setCacheDependency(ResourceObject $ro): void
    {
        $this->dependencyCollector->collect($ro);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        $state = $this->storage->get($uri);

        if (! $state instanceof ResourceState) {
            return null;
        }

        // Age is residence time since the state was stored, not derived from Last-Modified
        // (the content's last change time). Entries predating storedAt fall back to Last-Modified.
        $storedAt = $state->storedAt ?? (int) strtotime($state->headers[Header::LAST_MODIFIED]);
        $state->headers[Header::AGE] = (string) (time() - $storedAt);

        return $state;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function purge(AbstractUri $uri)
    {
        $tags = $this->cacheTags->forUri($uri);

        // A top-level purge is an application-initiated (manual) cache bust: wrap it in a
        // manual_purge scope so it stands out from automatic invalidation. A purge nested
        // inside a request GET or a write command stays an ordinary purge event there.
        if ($this->logger instanceof TopLevelAwareInterface && $this->logger->isTopLevel()) {
            $openId = $this->logger->open(new ManualPurgeContext((string) $uri));
            $purged = false;
            try {
                return $purged = $this->storage->invalidateTags($tags);
            } finally {
                $this->logger->close(new ManualPurgeResultContext($purged), $openId);
            }
        }

        $this->logger->event(new PurgeContext((string) $uri));

        return $this->storage->invalidateTags($tags);
    }

    private function getHttpCacheAnnotation(ResourceObject $ro): HttpCache|null
    {
        $attributes = (new ReflectionClass($ro))->getAttributes(HttpCache::class);

        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }

    private function getCacheableAnnotation(ResourceObject $ro): Cacheable|null
    {
        $attributes = (new ReflectionClass($ro))->getAttributes(Cacheable::class);

        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }

    private function getExpiryTime(ResourceObject $ro, Cacheable|null $cacheable = null): int
    {
        if ($cacheable === null) {
            return 0;
        }

        if ($cacheable->expiryAt !== '') {
            return $this->getExpiryAtSec($ro, $cacheable);
        }

        // A user-supplied expirySecond may be negative; the schemas declare "minimum": 0
        return max(0, $cacheable->expirySecond ?: $this->expiry->getTime($cacheable->expiry));
    }

    private function getExpiryAtSec(ResourceObject $ro, Cacheable $cacheable): int
    {
        if (! is_array($ro->body) || ! isset($ro->body[$cacheable->expiryAt])) {
            $msg = sprintf('%s::%s', $ro::class, $cacheable->expiryAt);

            throw new ExpireAtKeyNotExists($msg);
        }

        /** @var string $expiryAt */
        $expiryAt = $ro->body[$cacheable->expiryAt];

        // A past expiryAt yields no lifetime to set, which the storage stores as "no expiry"
        return max(0, (int) strtotime($expiryAt) - time());
    }
}
