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
use function array_unique;
use function assert;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
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
    private const KEY_DONUT = 'donut-';

    /** @var RepositoryLoggerInterface */
    private $logger;

    /** @var TagAwareAdapter */
    private $roPool;

    /** @var TagAwareAdapter */
    private $etagPool;

    /** @var PurgerInterface */
    private $purger;

    /** @var CacheKey */
    private $cacheKey;

    /** @var ResourceStorageSaver */
    private $saver;

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
        $this->purger = $etagDeleter;
        $this->cacheKey = $cacheKey;
        $this->saver = new ResourceStorageSaver(new CacheKey());
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
    public function get(AbstractUri $uri): ?ResourceState
    {
        $item = $this->roPool->getItem($this->getUriKey($uri, self::KEY_RO));
        assert($item instanceof ItemInterface);
        $state = $item->get();
        assert($state instanceof ResourceState || $state === null);

        return $state;
    }

    public function getDonut(AbstractUri $uri): ?ResourceDonut
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $item = $this->roPool->getItem($key);
        assert($item instanceof ItemInterface);
        $donut = $item->get();
        assert($donut instanceof ResourceDonut || $donut === null);

        return $donut;
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
        $this->deleteEtag($uri); // old
        $this->saveEtag($uri, $etag, $surrogateKeys, $ttl); // new
    }

    /**
     * {@inheritdoc}
     */
    public function deleteEtag(AbstractUri $uri)
    {
        $uriTag = ($this->cacheKey)($uri);
        $result = $this->invalidateTags([$uriTag]);
        ($this->purger)($uriTag);

        return $result;
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
        $value = ResourceState::create($ro, $body, null);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $this->logger->log('save-value uri:%s tags:%s ttl:%s', $ro->uri, $tags, $ttl);

        return $this->saver->__invoke($key, $value, $this->roPool, $ro->uri, $tags, $ttl);
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
        $value = ResourceState::create($ro, $body, $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);

        return $this->saver->__invoke($key, $value, $this->roPool, $ro->uri, $tags, $ttl);
    }

    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, ?int $sMaxAge): void
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $this->logger->log('save-donut uri:%s s-maxage:%s', $uri, $sMaxAge);

        $this->saver->__invoke($key, $donut, $this->roPool, $uri, [], $sMaxAge);
    }

    public function saveDonutView(ResourceObject $ro, ?int $ttl): bool
    {
        $resourceState = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $this->logger->log('save-donut-view uri:%s surrogate-keys:%s s-maxage:%s', $ro->uri, $tags, $ttl);

        return $this->saver->__invoke($key, $resourceState, $this->roPool, $ro->uri, $tags, $ttl);
    }

    public function deleteDonut(AbstractUri $uri): void
    {
        $this->logger->log('delete-donut uri:%s', (string) $uri);
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $this->roPool->delete($key);
    }

    /**
     * @return list<string>
     */
    private function getTags(ResourceObject $ro): array
    {
        $etag = $ro->headers['ETag'];
        $tags = [$etag, ($this->cacheKey)($ro->uri)];
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $tags = array_merge($tags, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }

        /** @var list<string> $uniqueTags */
        $uniqueTags = array_unique($tags);

        return $uniqueTags;
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
        $tags = $surrogateKeys ? explode(' ', $surrogateKeys) : [];
        $this->logger->log('save-etag uri:%s etag:%s surrogate-keys:%s', $uri, $etag, $surrogateKeys);
        $this->saver->__invoke($etag, 'etag', $this->etagPool, $uri, $tags, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateTags(array $tags): bool
    {
        $tag = $tags ? implode(' ', $tags) : '';
        $this->logger->log('invalidate-etag tags:%s', $tag);
        $valid1 = $this->roPool->invalidateTags($tags);
        $valid2 = $this->etagPool->invalidateTags($tags);

        return $valid1 && $valid2;
    }
}
