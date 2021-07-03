<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
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
    private $roPool;

    /** @var AdapterInterface */
    private $etagPool;

    /**
     * @Shared("pool")
     * @EtagPool("etagPool")
     */
    #[Shared('pool'), EtagPool('etagPool')]
    public function __construct(CacheItemPoolInterface $pool, ?CacheItemPoolInterface $etagPool = null)
    {
        assert($pool instanceof AdapterInterface);
        if ($etagPool instanceof AdapterInterface) {
            $this->roPool = new TagAwareAdapter($pool, $etagPool);
            $this->etagPool = $etagPool;

            return;
        }

        $this->roPool = new TagAwareAdapter($pool);
        $this->etagPool = $this->roPool;
    }

    /**
     * {@inheritdoc}
     */
    public function hasEtag(string $etag): bool
    {
        return $this->etagPool->hasItem(self::KEY_ETAG_VAL . $etag);
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
            $this->roPool->invalidateTags([$cachedEtag]); // remove ro
            $this->etagPool->deleteItem($cachedEtag);

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
        $ro = $this->roPool->getItem($this->getUriKey($uri, self::KEY_RO))->get();

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
        $item = $this->roPool->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);
        $etag = self::KEY_ETAG_VAL . $ro->headers['ETag'];
        $item->tag($etag);

        return $this->roPool->save($item);
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
        $item = $this->roPool->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);
        $etag = self::KEY_ETAG_VAL . $ro->headers['ETag'];
        $item->tag([$etag]);

        return $this->roPool->save($item);
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
        $cachedEtag = $this->etagPool->getItem($key)->get();

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
        $uriItem = $this->roPool->getItem($uriKey);
        $etagKey = self::KEY_ETAG_VAL . $etag;
        $uriItem->set($etagKey);
        $uriItem->expiresAfter($lifeTime);
        // save ETag value
        $this->etagPool->save($uriItem);

        $etagItem = $this->roPool->getItem($etagKey);
        $etagItem->set($uriKey);
        $etagItem->expiresAfter($lifeTime);
        $this->etagPool->save($etagItem);
    }
}
