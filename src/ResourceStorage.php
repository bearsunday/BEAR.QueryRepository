<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Psr\Cache\CacheItemPoolInterface;
use Ray\PsrCacheModule\Annotation\Shared;

use function assert;
use function explode;
use function is_array;
use function is_string;
use function sprintf;
use function str_replace;
use function strtoupper;

/**
 * @psalm-import-type ResourceState from ResourceStorageInterface
 */
final class ResourceStorage implements ResourceStorageInterface
{
    /**
     * ETag URI table prefix
     */
    private const KEY_ETAG_TABLE = 'etag-table-';

    /**
     * ETag value cache prefix
     */
    private const KEY_ETAG_VAL = 'etag-val-';

    /**
     * Resource object cache prefix
     */
    private const KEY_RO = 'ro-';

    /** @var CacheItemPoolInterface */
    private $cache;

    /**
     * @Shared
     */
    #[Shared]
    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function hasEtag(string $etag): bool
    {
        return $this->cache->hasItem(self::KEY_ETAG_VAL . $etag);
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function updateEtag(ResourceObject $ro, int $lifeTime)
    {
        assert(isset($ro->headers['ETag']));
        $uri = $this->getUriKey($ro->uri, self::KEY_ETAG_TABLE);
        // delete old ETag
        $this->deleteEtag($ro->uri);
        // save ETag uri
        $uriItem = $this->cache->getItem($uri);
        $etag = self::KEY_ETAG_VAL . $ro->headers['ETag'];
        $uriItem->set($etag);
        $uriItem->expiresAfter($lifeTime);
        // save ETag value
        $this->cache->save($uriItem);

        $etagItem = $this->cache->getItem($etag);
        $etagItem->set($uri);
        $etagItem->expiresAfter($lifeTime);
        $this->cache->save($etagItem);
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function deleteEtag(AbstractUri $uri)
    {
        $key = $this->getUriKey($uri, self::KEY_ETAG_TABLE);
        /** @var ?string $oldEtagKey */
        $oldEtagKey = $this->cache->getItem($key)->get();
        if (is_string($oldEtagKey)) {
            $this->cache->deleteItem($oldEtagKey);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        /** @var array{0: AbstractUri, 1: int, 2: array<string, string>, 3: mixed, 4: (null|string)}|null $ro */
        $ro = $this->cache->getItem($this->getUriKey($uri, self::KEY_RO))->get();

        return $ro;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(AbstractUri $uri): bool
    {
        $this->deleteEtag($uri);

        return $this->cache->deleteItem($this->getUriKey($uri, self::KEY_RO));
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function saveValue(ResourceObject $ro, int $ttl)
    {
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $val = [$ro->uri, $ro->code, $ro->headers, $body, null];
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $item = $this->cache->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);

        return $this->cache->save($item);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function saveView(ResourceObject $ro, int $ttl)
    {
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $val = [$ro->uri, $ro->code, $ro->headers, $body, $ro->view];
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $item = $this->cache->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);

        return $this->cache->save($item);
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

    private function getUriKey(AbstractUri $uri, string $type): string
    {
        $key =  $type . $this->getVaryUri($uri);

        return str_replace([':', '/'], ['_', '-'], $key);
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
