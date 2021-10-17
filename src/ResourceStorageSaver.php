<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\CacheItem;

use function assert;

final class ResourceStorageSaver
{
    /** @var UriTagInterface */
    private $uriTag;

    public function __construct(UriTagInterface $uriTag)
    {
        $this->uriTag = $uriTag;
    }

    /**
     * @param mixed        $value
     * @param list<string> $tags
     */
    public function __invoke(string $key, $value, CacheItemPoolInterface $pool, AbstractUri $uri, array $tags, ?int $ttl): bool
    {
        $cacheItem = $pool->getItem($key);
        $cacheItem->set($value);
        assert($cacheItem instanceof CacheItem);
        if ($ttl) {
            $cacheItem->expiresAfter($ttl);
        }

        $tags[] = ($this->uriTag)($uri);
        $cacheItem->tag($tags);

        return $pool->save($cacheItem);
    }
}
