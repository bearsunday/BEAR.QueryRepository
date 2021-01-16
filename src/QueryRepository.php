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
use ReflectionException;

use function assert;
use function get_class;
use function is_array;
use function is_string;
use function sprintf;
use function strpos;
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

    /** @var EtagSetterInterface */
    private $setEtag;

    public function __construct(
        EtagSetterInterface $setEtag,
        ResourceStorageInterface $storage,
        Reader $reader,
        Expiry $expiry
    ) {
        $this->setEtag = $setEtag;
        $this->reader = $reader;
        $this->storage = $storage;
        $this->expiry = $expiry;
    }

    /**
     * {@inheritdoc}
     *
     * @throws ReflectionException
     */
    public function put(ResourceObject $ro)
    {
        $ro->toString();
        $httpCache = $this->getHttpCacheAnnotation($ro);
        $cacheable = $this->getCacheableAnnotation($ro);
        ($this->setEtag)($ro, null, $httpCache);
        $lifeTime = $this->getExpiryTime($ro, $cacheable);
        if (isset($ro->headers['ETag'])) {
            $this->storage->updateEtag($ro, $lifeTime);
        }

        $this->setMaxAge($ro, $lifeTime);
        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            return $this->saveViewCache($ro, $lifeTime);
        }

        return $this->storage->saveValue($ro, $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        $data = $this->storage->get($uri);

        if ($data === false) {
            return false;
        }

        $age = time() - strtotime($data[2]['Last-Modified']);
        $data[2]['Age'] = $age;

        return $data;

    }

    /**
     * {@inheritdoc}
     */
    public function purge(AbstractUri $uri)
    {
        $this->storage->deleteEtag($uri);

        return $this->storage->delete($uri);
    }

    /**
     * @throws ReflectionException
     */
    private function getHttpCacheAnnotation(ResourceObject $ro): ?HttpCache
    {
        return $this->reader->getClassAnnotation(new ReflectionClass($ro), HttpCache::class);
    }

    /**
     * @return ?Cacheable
     *
     * @throws ReflectionException
     */
    private function getCacheableAnnotation(ResourceObject $ro): ?Cacheable
    {
        $annotation = $this->reader->getClassAnnotation(new ReflectionClass($ro), Cacheable::class);

        return $annotation;
    }

    private function getExpiryTime(ResourceObject $ro, ?Cacheable $cacheable = null): int
    {
        if ($cacheable === null) {
            return 0;
        }

        if ($cacheable->expiryAt) {
            return $this->getExpiryAtSec($ro, $cacheable);
        }

        return $cacheable->expirySecond ? $cacheable->expirySecond : (int) $this->expiry[$cacheable->expiry];
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

    /**
     * @return void
     */
    private function setMaxAge(ResourceObject $ro, int $age)
    {
        if ($age === 0) {
            return;
        }

        $setMaxAge = sprintf('max-age=%d', $age);
        $noCacheControleHeader = ! isset($ro->headers['Cache-Control']);
        /** @var array<string, string> $headers */
        $headers = $ro->headers;
        if ($noCacheControleHeader) {
            $ro->headers['Cache-Control'] = $setMaxAge;

            return;
        }

        $isMaxAgeAlreadyDefined = strpos($headers['Cache-Control'], 'max-age') !== false;
        if ($isMaxAgeAlreadyDefined) {
            return;
        }

        if (is_string($ro->headers['Cache-Control'])) {
            $ro->headers['Cache-Control'] .= ', ' . $setMaxAge;
        }
    }

    private function saveViewCache(ResourceObject $ro, int $lifeTime): bool
    {
        if (! $ro->view) {
            $ro->view = $ro->toString();
        }

        return $this->storage->saveView($ro, $lifeTime);
    }
}
