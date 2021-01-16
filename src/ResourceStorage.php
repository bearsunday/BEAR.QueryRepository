<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Cache\CacheProvider;

use function assert;
use function explode;
use function is_array;
use function is_string;
use function sprintf;
use function strtoupper;

final class ResourceStorage implements ResourceStorageInterface
{
    /**
     * Prefix for ETag URI
     */
    public const ETAG_TABLE = 'etag-table-';

    /**
     * Prefix of ETag value
     */
    public const ETAG_VAL = 'etag-val-';

    /** @var CacheProvider */
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
    public function hasEtag(string $etag): bool
    {
        return $this->cache->contains(self::ETAG_VAL . $etag);
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function updateEtag(ResourceObject $ro, int $lifeTime)
    {
        $varyUri = $this->getVaryUri($ro->uri);
        assert(isset($ro->headers['ETag']) && is_string($ro->headers['ETag']));
        $etag = self::ETAG_VAL . $ro->headers['ETag'];
        $uri = self::ETAG_TABLE . $varyUri;
        // delete old ETag
        $this->deleteEtag($ro->uri);
        // save ETag uri
        $this->cache->save($uri, $etag, $lifeTime);
        // save ETag value
        $this->cache->save($etag, $uri, $lifeTime);
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function deleteEtag(AbstractUri $uri)
    {
        $varyUri = self::ETAG_TABLE . $this->getVaryUri($uri); // invalidate etag
        /** @psalm-suppress MixedAssignment */
        $oldEtagKey = $this->cache->fetch($varyUri);
        if (is_string($oldEtagKey)) {
            $this->cache->delete($oldEtagKey);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        $varyUri = $this->getVaryUri($uri);
        /** @var array{0:string, 1: int, 2:array<string, string>, 3: mixed, 4: string}|false $roProps */
        $roProps = $this->cache->fetch($varyUri);

        return $roProps;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(AbstractUri $uri): bool
    {
        $this->deleteEtag($uri);
        $varyUri = $this->getVaryUri($uri);

        return $this->cache->delete($varyUri);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function saveValue(ResourceObject $ro, int $lifeTime)
    {
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $uri = $this->getVaryUri($ro->uri);
        $val = [$ro->uri, $ro->code, $ro->headers, $body, null];

        return $this->cache->save($uri, $val, $lifeTime);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function saveView(ResourceObject $ro, int $lifeTime)
    {
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $uri = $this->getVaryUri($ro->uri);
        $val = [$ro->uri, $ro->code, $ro->headers, $body, $ro->view];

        return $this->cache->save($uri, $val, $lifeTime);
    }

    /**
     * @param mixed $body
     *
     * @return mixed
     */
    private function evaluateBody($body)
    {
        if (! is_array($body)) {
            return $body;
        }

        /** @psalm-suppress MixedAssignment $item */
        foreach ($body as &$item) {
            if ($item instanceof RequestInterface) {
                $item = ($item)();
            }

            if ($item instanceof ResourceObject) {
                $item->body = $this->evaluateBody($item->body);
            }
        }

        return $body;
    }

    private function getVaryUri(AbstractUri $uri): string
    {
        if (! isset($_SERVER['X_VARY'])) {
            return (string) $uri;
        }

        $xvary = $_SERVER['X_VARY'];
        assert(is_string($xvary));
        $varys = explode(',', $xvary);
        $varyId = '';
        foreach ($varys as $vary) {
            $phpVaryKey = sprintf('X_%s', strtoupper($vary));
            if (isset($_SERVER[$phpVaryKey]) && is_string($_SERVER[$phpVaryKey])) {
                $varyId .= $_SERVER[$phpVaryKey];
            }
        }

        return $uri . $varyId;
    }
}
