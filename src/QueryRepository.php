<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\Request;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;
use Ray\Di\Di\Named;

class QueryRepository implements QueryRepositoryInterface
{
    /**
     * @var Cache
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
     * @param Cache $kvs
     *
     * @Named("kvs=BEAR\RepositoryModule\Annotation\Storage, expiry=BEAR\RepositoryModule\Annotation\ExpiryConfig")
     */
    public function __construct(Cache $kvs, Reader $reader, $expiry)
    {
        $this->reader = $reader;
        $this->kvs = $kvs;
        $this->expiry = $expiry;
    }

    /**
     * {@inheritdoc}
     */
    public function put(ResourceObject $ro)
    {
        if (isset($ro->headers['Etag'])) {
            $this->updateEtagDatabase($ro, $ro->headers['Etag']);
        }
        /* @var $cacheable Cacheable */
        $cacheable = $this->reader->getClassAnnotation(new \ReflectionClass($ro), Cacheable::class);
        $lifeTime = $this->getExpiryTime($cacheable);

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
     * @param string         $etag
     */
    private function updateEtagDatabase(ResourceObject $ro, $etag)
    {
        $uri = (string) $ro->uri;
        $scheme = substr($uri, 0, 4);
        if ($scheme !== 'page') {
            return;
        }
        $etagUri = 'resource-etag:' . $uri;
        $this->kvs->delete($this->kvs->fetch($etagUri));
        $etagId = 'etag-id:' . $etag;
        $this->kvs->save($etagId, $uri);     // etag => uri  for "is etag_exists?"
        $this->kvs->save($etagUri, $etagId); // uri  => etag for update etag by uri
    }

    /**
     * Delete etag in etag repository
     *
     * @param AbstractUri $uri
     */
    public function deleteEtagDatabase(AbstractUri $uri)
    {
        if ($uri->host !== 'page') {
            return;
        }
        $etagId = 'resource-etag:' . (string) $uri; // invalidate etag
        $oldEtagKey = $this->kvs->fetch($etagId);
        $this->kvs->delete($oldEtagKey);
    }

    private function getExpiryTime(Cacheable $cacheable = null)
    {
        if (is_null($cacheable)) {
            return 0;
        }

        return ($cacheable->expirySecond) ? $cacheable->expirySecond : $this->expiry[$cacheable->expiry];
    }
}
