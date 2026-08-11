<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\QueryRepository\Log\Context\ManualPurgeContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeResultContext;
use BEAR\QueryRepository\Log\Context\ManualStoreContext;
use BEAR\QueryRepository\Log\Context\ManualStoreResultContext;
use BEAR\QueryRepository\Log\Context\PurgeContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
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
        // A top-level put is a direct (non-AOP) cache write with no enclosing scope, so its
        // save events would be dropped at flush. Wrap it in a manual_store scope so the write
        // stays visible. A put nested inside a request GET or a write command keeps emitting
        // its save events under that scope, unchanged.
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
        $this->storage->deleteEtag($ro->uri);
        if ($ro->code === 200) {
            $this->setCacheDependency($ro);
        }

        $ro->toString();
        $cacheable = $this->getCacheableAnnotation($ro);
        $httpCache = $this->getHttpCacheAnnotation($ro);
        $ttl = $this->getExpiryTime($ro, $cacheable);
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

            // Materialize the child while HAL still has the Request in body.
            // AbstractRequest::__toString() memoizes the inner result, so
            // repeated casts here and in the HAL renderer share one
            // invocation regardless of the request implementation's
            // execution strategy.
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

        $state->headers[Header::AGE] = (string) (time() - (int) strtotime($state->headers[Header::LAST_MODIFIED]));

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

        // A past expiryAt means "already expired": TTL 0
        return max(0, (int) strtotime($expiryAt) - time());
    }
}
