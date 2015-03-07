<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Cache\Cache;

class QueryRepository implements QueryRepositoryInterface
{
    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @param Cache $kvs
     *
     * @Storage
     */
    public function __construct(Cache $kvs)
    {
        $this->kvs = $kvs;
    }

    /**
     * {@inheritdoc}
     */
    public function put(ResourceObject $ro)
    {
        $data = [$ro->code, $ro->headers, $ro->body, $ro->view];
        $uri = (string) $ro->uri;
        if (isset($ro->headers['Etag'])) {
            $this->updateEtagDatabase($ro, $ro->headers['Etag']);
        }
        return $this->kvs->save($uri, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function get(Uri $uri)
    {
        return $this->kvs->fetch((string) $uri);
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
        $etagId = 'resource-etag:' . (string) $ro->uri;
        $oldEtagKey = $this->kvs->fetch($etagId);
        $this->kvs->delete($oldEtagKey);
        $newEtagKey = 'etag-id:' . $etag;
        $this->kvs->save($newEtagKey, true);
        $this->kvs->save($etagId, $newEtagKey);
    }

    /**
     * Delete etag in etag repository
     *
     * @param $uri
     */
    public function deleteEtagDatabase($uri)
    {
        $etagId = 'resource-etag:' . (string) $uri;
        $oldEtagKey = $this->kvs->fetch($etagId);
        $this->kvs->delete($oldEtagKey);
    }
}
