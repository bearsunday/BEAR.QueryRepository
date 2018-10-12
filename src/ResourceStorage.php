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
use Doctrine\Common\Cache\CacheProvider;

final class ResourceStorage implements ResourceStorageInterface
{
    const ETAG_TABLE = 'etag-table-';

    const ETAG_VAL = 'etag-val-';

    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @Storage
     */
    public function __construct(CacheProvider $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function hasEtag(string $etag) : bool
    {
        return $this->cache->contains(self::ETAG_VAL . $etag);
    }

    /**
     * Update ETag
     */
    public function updateEtag(ResourceObject $ro)
    {
        $varyUri = $this->getVaryUri($ro->uri);
        $etag = self::ETAG_VAL . $ro->headers['ETag'];
        $uri = self::ETAG_TABLE . $varyUri;
        // delete old ETag
        $this->deleteEtag($ro->uri);
        // save ETag uri
        $this->cache->save($uri, $etag);
        // save ETag value
        $this->cache->save($etag, $uri);
    }

    /**
     * Delete etag in etag repository
     *
     * @param AbstractUri $uri
     */
    public function deleteEtag(AbstractUri $uri)
    {
        $uri = self::ETAG_TABLE . $this->getVaryUri($uri); // invalidate etag
        $oldEtagKey = $this->cache->fetch($uri);

        $this->cache->delete($oldEtagKey);
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        $uri = $this->getVaryUri($uri);

        return $this->cache->fetch($uri);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(AbstractUri $uri) : bool
    {
        $this->deleteEtag($uri);
        $uri = $this->getVaryUri($uri);

        return $this->cache->delete($uri);
    }

    /**
     * {@inheritdoc}
     */
    public function saveValue(ResourceObject $ro, int $lifeTime)
    {
        $body = $this->evaluateBody($ro->body);
        $uri = $this->getVaryUri($ro->uri);
        $val = [$ro->uri, $ro->code, $ro->headers, $body, null];

        return $this->cache->save($uri, $val, $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function saveView(ResourceObject $ro, int $lifeTime)
    {
        $body = $this->evaluateBody($ro->body);
        $uri = $this->getVaryUri($ro->uri);
        $val = [$ro->uri, $ro->code, $ro->headers, $body, $ro->view];

        return $this->cache->save($uri, $val, $lifeTime);
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
