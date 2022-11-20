<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\CacheItem;

use function assert;

final class ResourceStorageSaver
{
    /** @param list<string> $tags */
    public function __invoke(string $key, mixed $value, CacheItemPoolInterface $pool, array $tags, int|null $ttl): bool
    {
        $cacheItem = $pool->getItem($key);
        $cacheItem->set($value);
        assert($cacheItem instanceof CacheItem);
        $cacheItem->tag($tags);

        if ($ttl) {
            $cacheItem->expiresAfter($ttl);
        }

        return $pool->save($cacheItem);
    }
}
