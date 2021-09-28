<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\SerializableTagAwareAdapter as TagAwareAdapter;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Cache\CacheProvider;
use Psr\Cache\CacheItemPoolInterface;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;

use function array_merge;
use function assert;
use function explode;
use function is_array;
use function is_string;
use function sprintf;
use function str_replace;
use function strtoupper;

final class ResourceStorage implements ResourceStorageInterface
{
    /**
     * ETag URI table prefix
     */
    private const KEY_ETAG_TABLE = 'etag-t';

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
    public function __construct(
        ?CacheItemPoolInterface $pool = null,
        ?CacheItemPoolInterface $etagPool = null,
        ?CacheProvider $cache = null
    ) {
        if ($pool === null && $cache instanceof CacheProvider) {
            $this->injectDoctrineCache($cache);

            return;
        }

        assert($pool instanceof AdapterInterface);
        if ($etagPool instanceof AdapterInterface) {
            $this->roPool = new TagAwareAdapter($pool, $etagPool);
            $this->etagPool = $etagPool;

            return;
        }

        $this->roPool = new TagAwareAdapter($pool);
        $this->etagPool = $this->roPool;
    }

    private function injectDoctrineCache(CacheProvider $cache): void
    {
        $this->roPool = new TagAwareAdapter(new DoctrineAdapter($cache));
        $this->etagPool = $this->roPool;
    }

    /**
     * {@inheritdoc}
     */
    public function hasEtag(string $etag): bool
    {
        return $this->etagPool->hasItem($etag);
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
    public function get(AbstractUri $uri): ?ResourceState
    {
        $state = $this->roPool->getItem($this->getUriKey($uri, self::KEY_RO))->get();
        assert($state instanceof ResourceState || $state === null);

        return $state;
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
        $val = new ResourceState($ro, $body, null);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $item = $this->roPool->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);
        $tags = $this->getTags($ro);
        $item->tag($tags);

        return $this->roPool->save($item);
    }

    /**
     * @return list<string>
     */
    private function getTags(ResourceObject $ro): array
    {
        $etags = [$ro->headers['ETag']];
        if (isset($ro->headers[CacheDependency::CACHE_DEPENDENCY])) {
            $etags = array_merge($etags, explode(' ', $ro->headers[CacheDependency::CACHE_DEPENDENCY]));
        }

        return $etags;
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
        $val = new ResourceState($ro, $body, $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $item = $this->roPool->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);
        $tag = $this->getTags($ro);
        $item->tag($tag);

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
        $uriItem->set($etag);
        $uriItem->expiresAfter($lifeTime);
        // save ETag value
        $this->etagPool->save($uriItem);

        $etagItem = $this->roPool->getItem($etag);
        $etagItem->set($uriKey);
        $etagItem->expiresAfter($lifeTime);
        $this->etagPool->save($etagItem);
    }
}
