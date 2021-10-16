<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\Reader;
use ReflectionClass;

use function assert;
use function get_class;
use function is_array;
use function sprintf;
use function strtotime;
use function time;

final class QueryRepository implements QueryRepositoryInterface
{
    /** @var ResourceStorageInterface */
    private $storage;

    /** @var Reader */
    private $reader;

    /** @var Expiry */
    private $expiry;

    /** @var HeaderSetter */
    private $headerSetter;

    /** @var RepositoryLoggerInterface */
    private $logger;

    public function __construct(
        RepositoryLoggerInterface $logger,
        HeaderSetter $headerSetter,
        ResourceStorageInterface $storage,
        Reader $reader,
        Expiry $expiry
    ) {
        $this->headerSetter = $headerSetter;
        $this->reader = $reader;
        $this->storage = $storage;
        $this->expiry = $expiry;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function put(ResourceObject $ro)
    {
        $this->logger->log('put-query-repository uri:%s', $ro->uri);
        $ro->toString();
        $cacheable = $this->getCacheableAnnotation($ro);
        $httpCache = $this->getHttpCacheAnnotation($ro);
        $ttl = $this->getExpiryTime($ro, $cacheable);
        ($this->headerSetter)($ro, $ttl, $httpCache);
        if (isset($ro->headers[Header::ETAG])) {
            $etag = $ro->headers[Header::ETAG];
            $surrogateKeys = $ro->headers[Header::SURROGATE_KEY] ?? '';
            $this->storage->updateEtag($ro->uri, $etag, $surrogateKeys, $ttl);
        }

        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            return $this->storage->saveView($ro, $ttl);
        }

        return $this->storage->saveValue($ro, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri): ?ResourceState
    {
        $state = $this->storage->get($uri);

        if ($state === null) {
            return null;
        }

        $state->headers[Header::AGE] = (string) (time() - strtotime($state->headers[Header::LAST_MODIFIED]));

        return $state;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(AbstractUri $uri)
    {
        $this->logger->log('purge-query-repository uri:%s', $uri);
        $this->logger->log('delete-etag uri:%s', $uri);

        return $this->storage->deleteEtag($uri);
    }

    private function getHttpCacheAnnotation(ResourceObject $ro): ?HttpCache
    {
        return $this->reader->getClassAnnotation(new ReflectionClass($ro), HttpCache::class);
    }

    private function getCacheableAnnotation(ResourceObject $ro): ?Cacheable
    {
        return $this->reader->getClassAnnotation(new ReflectionClass($ro), Cacheable::class);
    }

    private function getExpiryTime(ResourceObject $ro, ?Cacheable $cacheable = null): int
    {
        if ($cacheable === null) {
            return 0;
        }

        if ($cacheable->expiryAt) {
            return $this->getExpiryAtSec($ro, $cacheable);
        }

        return $cacheable->expirySecond ? $cacheable->expirySecond : $this->expiry->getTime($cacheable->expiry);
    }

    private function getExpiryAtSec(ResourceObject $ro, Cacheable $cacheable): int
    {
        if (! isset($ro->body[$cacheable->expiryAt])) {
            $msg = sprintf('%s::%s', get_class($ro), $cacheable->expiryAt);

            throw new ExpireAtKeyNotExists($msg);
        }

        assert(is_array($ro->body));
        $expiryAt = (string) $ro->body[$cacheable->expiryAt];

        return strtotime($expiryAt) - time();
    }
}
