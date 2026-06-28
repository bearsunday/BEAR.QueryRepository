<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\QueryRepository\Log\Context\PurgeContext;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use ReflectionClass;

use function is_array;
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
        // The cache layer is a nested logger: its save events nest under whatever scope is
        // open above (the resource invocation from BEAR.EventSourcing, or a GET/command
        // interceptor). It does not root its own session.
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

        return $cacheable->expirySecond ?: $this->expiry->getTime($cacheable->expiry);
    }

    private function getExpiryAtSec(ResourceObject $ro, Cacheable $cacheable): int
    {
        if (! is_array($ro->body) || ! isset($ro->body[$cacheable->expiryAt])) {
            $msg = sprintf('%s::%s', $ro::class, $cacheable->expiryAt);

            throw new ExpireAtKeyNotExists($msg);
        }

        /** @var string $expiryAt */
        $expiryAt = $ro->body[$cacheable->expiryAt];

        return (int) strtotime($expiryAt) - time();
    }
}
