<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\Di\Named;

class QueryRepository implements QueryRepositoryInterface
{
    const ETAG_BY_URI = 'etag-by-uri';

    /**
     * @var CacheProvider
     */
    private $kvs;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var array
     */
    private $expiry;

    /**
     * @var EtagSetterInterface
     */
    private $setEtag;

    /**
     * @param EtagSetterInterface $setEtag
     * @param CacheProvider       $kvs
     * @param Reader              $reader
     * @param string              $expiry
     *
     * @Named("kvs=BEAR\RepositoryModule\Annotation\Storage, expiry=BEAR\RepositoryModule\Annotation\ExpiryConfig")
     */
    public function __construct(
        EtagSetterInterface $setEtag,
        CacheProvider $kvs,
        Reader $reader,
        $expiry
    ) {
        $this->setEtag = $setEtag;
        $this->reader = $reader;
        $this->kvs = $kvs;
        $this->expiry = $expiry;
    }

    /**
     * {@inheritdoc}
     */
    public function put(ResourceObject $ro)
    {
        $this->setEtag->__invoke($ro);
        if (isset($ro->headers['ETag'])) {
            $this->updateEtagDatabase($ro);
        }
        /* @var $cacheable Cacheable */
        $cacheable = $this->reader->getClassAnnotation(new \ReflectionClass($ro), Cacheable::class);
        $lifeTime = $this->getExpiryTime($cacheable);
        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            (string) $ro;
        }
        return $this->kvs->save((string) $ro->uri, $ro, $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function get(Uri $uri)
    {
        $ro = $this->kvs->fetch((string) $uri);
        if ($ro === false) {
            return false;
        }

        return [$ro->code, $ro->headers, $ro->body, $ro->view];
    }

    /**
     * {@inheritdoc}
     */
    public function purge(Uri $uri)
    {
        $this->deleteEtagDatabase($uri);

        return $this->kvs->delete((string) $uri);
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
        $etagId = HttpCache::ETAG_KEY . $etag;
        $this->kvs->save($etagId, $uri);     // save etag
        $this->kvs->save($etagUri, $etagId); // save uri  mapping etag
    }

    /**
     * Delete etag in etag repository
     *
     * @param AbstractUri $uri
     */
    public function deleteEtagDatabase(AbstractUri $uri)
    {
        $etagId = self::ETAG_BY_URI . (string) $uri; // invalidate etag
        $oldEtagKey = $this->kvs->fetch($etagId);

        $this->kvs->delete($oldEtagKey);
    }

    /**
     * @param Cacheable $cacheable
     *
     * @return int
     */
    private function getExpiryTime(Cacheable $cacheable = null)
    {
        if (is_null($cacheable)) {
            return 0;
        }

        return ($cacheable->expirySecond) ? $cacheable->expirySecond : $this->expiry[$cacheable->expiry];
    }
}
