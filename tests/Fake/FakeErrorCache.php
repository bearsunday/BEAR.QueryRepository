<?php

namespace BEAR\QueryRepository;

use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * Error cache driver
 */
class FakeErrorCache implements AdapterInterface
{
    public function getItem($key): CacheItem
    {
        throw new RuntimeException();
    }

    public function getItems(array $keys = []): iterable
    {
    }

    public function hasItem($key): bool
    {
    }

    public function clear(string $prefix = ''): bool
    {
    }

    public function deleteItem($key): bool
    {
    }

    public function deleteItems(array $keys): bool
    {
    }

    public function save(CacheItemInterface $item): bool
    {
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
    }

    public function commit(): bool
    {
    }
}
