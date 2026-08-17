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
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use ReflectionClass;

use function is_array;
use function max;
use function sprintf;
use function strtotime;
use function time;

final readonly class QueryRepository implements QueryRepositoryInterface
{
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private HeaderSetter $headerSetter,
        private ResourceStorageInterface $storage,
        private Expiry $expiry,
        private CacheDependencyInterface $cacheDependency,
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
        // The writer knows its own purpose: this deleteEtag clears the entry about to be
        // rewritten below. The marker records that, so readers never have to infer
        // cleanup-vs-invalidation from tag correlation.
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->storage->deleteEtag($ro->uri);
        if ($ro->code === 200) {
            $this->setCacheDependency($ro);
        }

        $ro->toString();
        $cacheable = $this->getCacheableAnnotation($ro);
        $httpCache = $this->getHttpCacheAnnotation($ro);
        $ttl = $this->getExpiryTime($ro, $cacheable);
        if ($cacheable instanceof Cacheable) {
            $this->logger->event($this->cachePolicy($ro, $cacheable, $ttl));
        }

        ($this->headerSetter)($ro, $ttl, $httpCache);
        if (isset($ro->headers[Header::ETAG])) {
            $etag = $ro->headers[Header::ETAG];
            $surrogateKeys = $ro->headers[Header::SURROGATE_KEY] ?? '';
            $this->storage->saveEtag($ro->uri, $etag, $surrogateKeys, $ttl);
        }

        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            return $this->storage->saveView($ro, $ttl);
        }

        return $this->storage->saveValue($ro, $ttl);
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
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            return;
        }

        /** @var mixed $body */
        foreach ((array) $ro->body as $body) {
            if (! ($body instanceof AbstractRequest)) {
                continue;
            }

            // Materialize the child while HAL still has the Request in body: the ETag read
            // below is set on the child resource by its own interceptor, so it only exists
            // once the child has run. AbstractRequest::__toString() memoizes the result,
            // so the renderer reads this same run - but only readers that consult the memo
            // do (see ResourceStorage::materialize(); __invoke() re-executes).
            (string) $body;
            if (! isset($body->resourceObject->headers[Header::ETAG])) {
                continue;
            }

            $this->cacheDependency->depends($ro, $body->resourceObject);
        }
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
        // A top-level purge is an application-initiated (manual) cache bust: wrap it in a
        // manual_purge scope so it stands out from automatic invalidation. A purge nested
        // inside a request GET or a write command stays an ordinary purge event there.
        if ($this->logger instanceof TopLevelAwareInterface && $this->logger->isTopLevel()) {
            $openId = $this->logger->open(new ManualPurgeContext((string) $uri));
            $purged = false;
            try {
                return $purged = $this->storage->deleteEtag($uri);
            } finally {
                $this->logger->close(new ManualPurgeResultContext($purged), $openId);
            }
        }

        $this->logger->event(new PurgeContext((string) $uri));

        return $this->storage->deleteEtag($uri);
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
