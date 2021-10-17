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
use Symfony\Contracts\Cache\ItemInterface;

use function array_merge;
use function assert;
use function explode;
use function is_array;
use function is_int;
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

    /**
     * Resource static cache prifix
     */
    private const KEY_STATIC = 'st-';

    /** @var RepositoryLoggerInterface */
    private $logger;

    /** @var TagAwareAdapter */
    private $roPool;

    /** @var TagAwareAdapter */
    private $etagPool;

    /** @var PurgerInterface */
    private $etagDeleter;

    /** @var CacheKey */
    private $cacheKey;

    /**
     * @Shared("pool")
     * @EtagPool("etagPool")
     */
    #[Shared('pool'), EtagPool('etagPool')]
    public function __construct(
        RepositoryLoggerInterface $logger,
        PurgerInterface $etagDeleter,
        CacheKey $cacheKey,
        ?CacheItemPoolInterface $pool = null,
        ?CacheItemPoolInterface $etagPool = null,
        ?CacheProvider $cache = null
    ) {
        $this->logger = $logger;
        $this->etagDeleter = $etagDeleter;
        $this->cacheKey = $cacheKey;
        if ($pool === null && $cache instanceof CacheProvider) {
            $this->injectDoctrineCache($cache);

            return;
        }

        assert($pool instanceof AdapterInterface);
        if ($etagPool instanceof AdapterInterface) {
            $this->roPool = new TagAwareAdapter($pool, $etagPool);
            $this->etagPool = new TagAwareAdapter($etagPool);

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
    public function updateEtag(AbstractUri $uri, string $etag, string $surrogateKeys, ?int $ttl)
    {
        $this->logger->log('update-etag uri:%s etag:%s surrogate-keys:%s', $uri, $etag, $surrogateKeys);
        $this->deleteEtag($uri); // old
        $this->saveEtag($uri, $etag, $surrogateKeys, $ttl); // new
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
            $this->etagPool->invalidateTags([$cachedEtag]);
            ($this->etagDeleter)($cachedEtag);

            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri): ?ResourceState
    {
        $item = $this->roPool->getItem($this->getUriKey($uri, self::KEY_RO));
        assert($item instanceof ItemInterface);
        $state = $item->get();
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
        $this->logger->log('save-value uri:%s ttl:%s', $ro->uri, $ttl);
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $val = ResourceState::create($ro, $body, null);
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
        $etag = $ro->headers['ETag'];
        $tags = [$etag, (new CacheKey())($ro->uri)];
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $tags = array_merge($tags, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }

        return $tags;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function saveView(ResourceObject $ro, int $ttl)
    {
        $this->logger->log('save-view uri:%s ttl:%s', $ro->uri, $ttl);
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $val = ResourceState::create($ro, $body, $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $item = $this->roPool->getItem($key);
        $item->set($val);
        $item->expiresAfter($ttl);
        $tag = $this->getTags($ro);
        $item->tag($tag);

        return $this->roPool->save($item);
    }

    public function saveDonutView(ResourceObject $ro, ?int $ttl): bool
    {
        $val = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $item = $this->roPool->getItem($key);
        if (is_int($ttl)) {
            $item->expiresAfter($ttl);
        }

        $item->set($val);
        $tag = $this->getTags($ro);
        $item->tag($tag);

        // save view
        return $this->roPool->save($item);
    }

    public function getDonut(AbstractUri $uri): ?ResourceDonut
    {
        $key = $this->getUriKey($uri, self::KEY_STATIC);
        $item = $this->roPool->getItem($key);
        assert($item instanceof ItemInterface);
        $donut = $item->get();
        assert($donut instanceof ResourceDonut || $donut === null);

        return $donut;
    }

    public function deleteDonut(AbstractUri $uri): void
    {
        $this->logger->log('delete-donut uri:%s', (string) $uri);
        $key = $this->getUriKey($uri, self::KEY_STATIC);
        $this->roPool->delete($key);
    }

    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, ?int $sMaxAge): void
    {
        $this->logger->log('save-donut uri:%s s-maxage:%s', $uri, $sMaxAge);
        $key = $this->getUriKey($uri, self::KEY_STATIC);
        $item = $this->roPool->getItem($key);
        $item->set($donut);
        if (is_int($sMaxAge)) {
            $item->expiresAfter($sMaxAge);
        }

        assert($this->roPool->save($item));
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
        return $type . ($this->cacheKey)($uri) . (isset($_SERVER['X_VARY']) ? $this->getVary() : '');
    }

    private function getVary(): string
    {
        $xvary = $_SERVER['X_VARY'];
        assert(is_string($xvary));
        $varys = explode(',', $xvary);
        $varyString = '';
        foreach ($varys as $vary) {
            $phpVaryKey = sprintf('X_%s', strtoupper($vary));
            if (isset($_SERVER[$phpVaryKey]) && is_string($_SERVER[$phpVaryKey])) {
                $varyString .= $_SERVER[$phpVaryKey];
            }
        }

        return $varyString;
    }

    private function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, ?int $ttl): void
    {
        // save ETag uri
        $uriKey = $this->getUriKey($uri, self::KEY_ETAG_TABLE);
        $uriItem = $this->roPool->getItem($uriKey);
        $uriItem->set($etag);
        if (is_int($ttl)) {
            $uriItem->expiresAfter($ttl);
        }

        // save ETag value
        $this->etagPool->save($uriItem);

        $etagItem = $this->roPool->getItem($etag);
        $etagItem->set($uriKey);
        $tags = $surrogateKeys ? explode(' ', $surrogateKeys) : null;
        if (is_array($tags)) {
            $etagItem->tag($tags);
        }

        if (is_int($ttl)) {
            $etagItem->expiresAfter($ttl);
        }

        $this->etagPool->save($etagItem);
    }
}
