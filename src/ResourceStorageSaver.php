<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\CacheItem;

use function assert;

final class ResourceStorageSaver
{
    /**
     * @param mixed        $value
     * @param list<string> $tags
     */
    public function __invoke(string $key, $value, CacheItemPoolInterface $pool, AbstractUri $uri, array $tags, ?int $ttl): bool
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
