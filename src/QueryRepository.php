<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;
use ReflectionClass;

use function is_array;
use function sprintf;
use function strtotime;
use function time;

final class QueryRepository implements QueryRepositoryInterface
{
    public function __construct(
        private readonly RepositoryLoggerInterface $logger,
        private readonly HeaderSetter $headerSetter,
        private readonly ResourceStorageInterface $storage,
        private readonly Expiry $expiry,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function put(ResourceObject $ro)
    {
        $this->logger->log('put-query-repository uri:%s', $ro->uri);
        $this->storage->deleteEtag($ro->uri);
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
        $this->logger->log('purge-query-repository uri:%s', $uri);

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
