<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;

class QueryRepository implements QueryRepositoryInterface
{
    const ETAG_BY_URI = 'etag-by-uri';

    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var Expiry
     */
    private $expiry;

    /**
     * @var EtagSetterInterface
     */
    private $setEtag;

    /**
     * @Storage("kvs")
     */
    public function __construct(
        EtagSetterInterface $setEtag,
        Cache $kvs,
        Reader $reader,
        Expiry $expiry
    ) {
        $this->setEtag = $setEtag;
        $this->reader = $reader;
        $this->kvs = $kvs;
        $this->expiry = $expiry;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \ReflectionException
     */
    public function put(ResourceObject $ro)
    {
        $ro->toString();
        $httpCache = $this->getHttpCacheAnnotation($ro);
        $cacheable = $this->getCacheableAnnotation($ro);
        /** @var Cacheable $cacheable|null */
        ($this->setEtag)($ro, null, $httpCache);
        if (isset($ro->headers['ETag'])) {
            $this->updateEtagDatabase($ro);
        }
        $body = $this->evaluateBody($ro->body);
        $lifeTime = $this->getExpiryTime($ro, $cacheable);
        $this->setMaxAge($ro, $lifeTime);
        $id = $this->getVaryUri($ro->uri);
        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            if (! $ro->view) {
                // render
                $ro->view = $ro->toString();
            }

            return $this->kvs->save($id, [$ro->uri, $ro->code, $ro->headers, $body, $ro->view], $lifeTime);
        }
        // "value" cache type
        return $this->kvs->save($id, [$ro->uri, $ro->code, $ro->headers, $body, null], $lifeTime);
    }

    /**
     * @throws \ReflectionException
     */
    private function getHttpCacheAnnotation(ResourceObject $ro)
    {
        $annotation =  $this->reader->getClassAnnotation(new \ReflectionClass($ro), HttpCache::class);
        if ($annotation instanceof HttpCache || $annotation  === null) {
            return $annotation;
        }
        throw new \LogicException();
    }

    /**
     * @throws \ReflectionException
     */
    private function getCacheableAnnotation(ResourceObject $ro)
    {
        $annotation =  $this->reader->getClassAnnotation(new \ReflectionClass($ro), Cacheable::class);
        if ($annotation instanceof Cacheable || $annotation  === null) {
            return $annotation;
        }
        throw new \LogicException();
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        $id = $this->getVaryUri($uri);
        $data = $this->kvs->fetch($id);
        if ($data === false) {
            return false;
        }
        $age = \time() - \strtotime($data[2]['Last-Modified']);
        $data[2]['Age'] = $age;

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(AbstractUri $uri)
    {
        $id = $this->getVaryUri($uri);
        $this->deleteEtagDatabase($uri);

        return $this->kvs->delete($id);
    }

    /**
     * Delete etag in etag repository
     *
     * @param AbstractUri $uri
     */
    public function deleteEtagDatabase(AbstractUri $uri)
    {
        $etagId = self::ETAG_BY_URI . $this->getVaryUri($uri); // invalidate etag

        $oldEtagKey = $this->kvs->fetch($etagId);

        $this->kvs->delete($oldEtagKey);
    }

    /**
     * @param mixed $body
     *
     * @return mixed
     */
    private function evaluateBody($body)
    {
        if (! \is_array($body)) {
            return $body;
        }
        foreach ($body as &$item) {
            if ($item instanceof RequestInterface) {
                $item = ($item)();
            }
        }

        return $body;
    }

    /**
     * Update etag in etag repository
     *
     * @param ResourceObject $ro
     */
    private function updateEtagDatabase(ResourceObject $ro)
    {
        $etag = $ro->headers['ETag'];
        $uri = (string) $ro->uri;
        $etagUri = self::ETAG_BY_URI . $uri;
        $oldEtag = $this->kvs->fetch($etagUri);
        if ($oldEtag) {
            $this->kvs->delete($oldEtag);
        }
        $etagId = \BEAR\QueryRepository\HttpCache::ETAG_KEY . $etag;
        $this->kvs->save($etagId, $uri);     // save etag
        $this->kvs->save($etagUri, $etagId); // save uri  mapping etag
    }

    private function getExpiryTime(ResourceObject $ro, Cacheable $cacheable = null) : int
    {
        if ($cacheable === null) {
            return 0;
        }

        if ($cacheable->expiryAt) {
            return $this->getExpiryAtSec($ro, $cacheable);
        }

        return $cacheable->expirySecond ? $cacheable->expirySecond : $this->expiry[$cacheable->expiry];
    }

    private function getExpiryAtSec(ResourceObject $ro, Cacheable $cacheable) : int
    {
        if (! isset($ro->body[$cacheable->expiryAt])) {
            $msg = \sprintf('%s::%s', \get_class($ro), $cacheable->expiryAt);
            throw new ExpireAtKeyNotExists($msg);
        }
        $expiryAt = $ro->body[$cacheable->expiryAt];
        $sec = \strtotime($expiryAt) - \time();

        return $sec;
    }

    private function setMaxAge(ResourceObject $ro, int $age)
    {
        $setMaxAge = \sprintf('max-age=%d', $age);
        if (isset($ro->headers['Cache-Control'])) {
            $ro->headers['Cache-Control'] .= ', ' . $setMaxAge;

            return;
        }
        $ro->headers['Cache-Control'] = $setMaxAge;
    }

    private function getVaryUri(AbstractUri $uri) : string
    {
        if (! isset($_SERVER['X_VARY'])) {
            return (string) $uri;
        }
        $varys = \explode(',', $_SERVER['X_VARY']);
        $varyId = '';
        foreach ($varys as $vary) {
            $phpVaryKey = \sprintf('X_%s', \strtoupper($vary));
            if (isset($_SERVER[$phpVaryKey])) {
                $varyId .= $_SERVER[$phpVaryKey];
            }
        }

        return (string) $uri . $varyId;
    }
}
