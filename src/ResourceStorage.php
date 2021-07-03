<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Psr\Cache\CacheItemPoolInterface;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

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
    private const KEY_ETAG_TABLE = 'etag-t';

    /**
     * ETag value cache prefix
     */
    private const KEY_ETAG_VAL = 'etag-v';

    /**
     * Resource object cache prefix
     */
    private const KEY_RO = 'ro-';

    /** @var TagAwareAdapter */
    private $cache;

    /**
     * @Shared
     */
    #[Shared]
    public function __construct(CacheItemPoolInterface $cache)
    {
        assert($cache instanceof AdapterInterface);
        $this->cache = new TagAwareAdapter($cache);
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
    public function updateEtag(AbstractUri $uri, string $etag, int $lifeTime)
    {
        $this->deleteEtag($uri); // old
        $this->saveEtag($uri, $etag, $lifeTime); // new
    }

    /**
     * {@inheritdoc}
     */
    public function deleteEtag(AbstractUri $uri)
    {
        $cachedEtag = $this->loadEtag($uri);
        if (is_string($cachedEtag)) {
            $this->cache->invalidateTags([$cachedEtag]); // remove ro
            $this->cache->deleteItem($cachedEtag);

            return true;
        }

        return false;
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
        $etag = self::KEY_ETAG_VAL . $ro->headers['ETag'];
        $item->tag($etag);

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
        $etag = self::KEY_ETAG_VAL . $ro->headers['ETag'];
        $item->tag([$etag]);

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

    private function loadEtag(AbstractUri $uri): ?string
    {
        $key = $this->getUriKey($uri, self::KEY_ETAG_TABLE);
        /** @var ?string $cachedEtag */
        $cachedEtag = $this->cache->getItem($key)->get();

        return $cachedEtag;
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

    private function saveEtag(AbstractUri $uri, string $etag, int $lifeTime): void
    {
        // save ETag uri
        $uriKey = $this->getUriKey($uri, self::KEY_ETAG_TABLE);
        $uriItem = $this->cache->getItem($uriKey);
        $etagKey = self::KEY_ETAG_VAL . $etag;
        $uriItem->set($etagKey);
        $uriItem->expiresAfter($lifeTime);
        // save ETag value
        $this->cache->save($uriItem);

        $etagItem = $this->cache->getItem($etagKey);
        $etagItem->set($uriKey);
        $etagItem->expiresAfter($lifeTime);
        $this->cache->save($etagItem);
    }
}
