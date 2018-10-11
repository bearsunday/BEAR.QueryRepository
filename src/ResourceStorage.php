<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Cache\Cache;

final class ResourceStorage implements ResourceStorageInterface
{
    const MARK = '1';

    const ETAG_BY_URI = 'etag-by-uri';

    const ETAG_PREFIX = 'etag-';

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @Storage
     */
    public function __construct(Cache $etagRepo)
    {
        $this->cache = $etagRepo;
    }

    /**
     * {@inheritdoc}
     */
    public function hasEtag(string $etag) : bool
    {
        return $this->cache->contains(self::ETAG_PREFIX . $etag);
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        return $this->cache->fetch((string) $uri);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(AbstractUri $uri) : bool
    {
        $this->deleteEtag($uri);

        return $this->cache->delete((string) $uri);
    }

    /**
     * {@inheritdoc}
     */
    public function saveValue(ResourceObject $ro, int $lifeTime)
    {
        $body = $this->evaluateBody($ro->body);

        return $this->cache->save((string) $ro->uri, [$ro->uri, $ro->code, $ro->headers, $body, null], $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function saveView(ResourceObject $ro, int $lifeTime)
    {
        $body = $this->evaluateBody($ro->body);

        return $this->cache->save((string) $ro->uri, [$ro->uri, $ro->code, $ro->headers, $body, $ro->view], $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function updateEtag(ResourceObject $ro)
    {
        $etag = $ro->headers['ETag'];
        $uri = (string) $ro->uri;
        $etagUri = self::ETAG_BY_URI . $uri;
        $oldEtag = $this->cache->fetch($etagUri);
        if ($oldEtag) {
            $this->cache->delete($oldEtag);
        }
        $etagId = self::ETAG_PREFIX . $etag;
        $this->cache->save($etagId, $uri);     // save etag
        $this->cache->save($etagUri, $etagId); // save uri  mapping etag
    }

    /**
     * Delete etag in etag repository
     *
     * @param AbstractUri $uri
     */
    private function deleteEtag(AbstractUri $uri)
    {
        $etagId = self::ETAG_BY_URI . (string) $uri; // invalidate etag
        $oldEtagKey = $this->cache->fetch($etagId);

        $this->cache->delete($oldEtagKey);
    }

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
}
