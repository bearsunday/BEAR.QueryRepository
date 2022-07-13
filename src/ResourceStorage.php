<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\KnownTagTtl;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Cache\CacheProvider;
use Psr\Cache\CacheItemPoolInterface;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
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
    use ResourceStorageCacheableTrait;

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

    /** @var UriTagInterface */
    private $uriTag;

    /** @var ResourceStorageSaver */
    private $saver;

    /** @var float */
    private $knownTagTtl;

    /**
     * @Shared("pool")
     * @EtagPool("etagPool")
     * @KnownTagTtl("knownTagTtl")
     */
    #[Shared('pool'), EtagPool('etagPool'), KnownTagTtl('knownTagTtl')]
    public function __construct(
        RepositoryLoggerInterface $logger,
        PurgerInterface $etagDeleter,
        UriTagInterface $uriTag,
        ?CacheItemPoolInterface $pool = null,
        ?CacheItemPoolInterface $etagPool = null,
        ?CacheProvider $cache = null,
        float $knownTagTtl = 0.0
    ) {
        $this->logger = $logger;
        $this->purger = $etagDeleter;
        $this->uriTag = $uriTag;
        $this->saver = new ResourceStorageSaver();
        if ($pool === null && $cache instanceof CacheProvider) {
            $this->injectDoctrineCache($cache);

            return;
        }

        $this->knownTagTtl = $knownTagTtl;
        assert($pool instanceof AdapterInterface);
        $etagPool =  $etagPool instanceof AdapterInterface ? $etagPool : $pool;
        $this->roPool = new TagAwareAdapter($pool, $etagPool, $knownTagTtl);
        $this->etagPool = new TagAwareAdapter($etagPool, $etagPool, $knownTagTtl);
    }

    private function injectDoctrineCache(CacheProvider $cache): void
    {
        /** @psalm-suppress DeprecatedClass */
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

    public function deleteDonut(AbstractUri $uri): void
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $item = $this->roPool->deleteItem($key);
        $this->deleteEtag($uri);
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
     */
    public function deleteEtag(AbstractUri $uri)
    {
        $uriTag = ($this->uriTag)($uri);

        return $this->invalidateTags([$uriTag]);
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateTags(array $tags): bool
    {
        $tag = $tags !== [] ? implode(' ', $tags) : '';
        $this->logger->log('invalidate-etag tags:%s', $tag);
        $valid1 = $this->roPool->invalidateTags($tags);
        $valid2 = $this->etagPool->invalidateTags($tags);
        ($this->purger)(implode(' ', $tags));

        return $valid1 && $valid2;
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

        return $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
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

        return $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
    }

    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, ?int $sMaxAge, SurrogateKeys $keys): void
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $this->logger->log('save-donut uri:%s s-maxage:%s', $uri, $sMaxAge);

        $this->saver->__invoke($key, $donut, $this->roPool, $keys->surrogateKeys, $sMaxAge);
    }

    public function saveDonutView(ResourceObject $ro, ?int $ttl): bool
    {
        $resourceState = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $this->logger->log('save-donut-view uri:%s surrogate-keys:%s s-maxage:%s', $ro->uri, $tags, $ttl);

        return $this->saver->__invoke($key, $resourceState, $this->roPool, $tags, $ttl);
    }

    /**
     * @return list<string>
     */
    private function getTags(ResourceObject $ro): array
    {
        $etag = $ro->headers['ETag'];
        $tags = [$etag, ($this->uriTag)($ro->uri)];
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
        return $type . ($this->uriTag)($uri) . (isset($_SERVER['X_VARY']) ? $this->getVary() : '');
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

    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, ?int $ttl): void
    {
        $tags = $surrogateKeys !== '' ? explode(' ', $surrogateKeys) : [];
        $tags[] = (new UriTag())($uri);
        /** @var list<string> $uniqueTags */
        $uniqueTags = array_unique($tags);
        $this->logger->log('save-etag uri:%s etag:%s surrogate-keys:%s', $uri, $etag, $uniqueTags);
        $this->saver->__invoke($etag, 'etag', $this->etagPool, $uniqueTags, $ttl);
    }
}
