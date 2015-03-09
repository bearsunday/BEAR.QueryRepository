<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\AbstractUri;
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
        if ($ro->uri->host !== 'page') {
            return;
        }
        $uri = (string) $ro->uri;
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
}
