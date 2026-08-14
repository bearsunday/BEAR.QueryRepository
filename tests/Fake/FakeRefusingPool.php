<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * A pool that reads normally but refuses one kind of write.
 *
 * Models the stores that quietly do not happen: a read-only replica, a full or
 * memory-pressured server, an eviction storm, a tag index that cannot be updated.
 * (Symfony's NullAdapter is not usable for this — wrapped in TagAwareAdapter its
 * save() reports success, which is the very kind of silent lie this log exposes.)
 *
 * `throwOnSave` models the loud sibling: a server that is gone rather than full, so the
 * write raises instead of reporting failure.
 */
final class FakeRefusingPool implements TagAwareAdapterInterface
{
    public function __construct(
        private readonly TagAwareAdapterInterface $pool,
        private readonly bool $refuseSave = true,
        private readonly bool $refuseInvalidation = false,
        private readonly bool $throwOnSave = false,
    ) {
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($this->throwOnSave) {
            throw new RuntimeException('cache server down');
        }

        return $this->refuseSave ? false : $this->pool->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->refuseSave ? false : $this->pool->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->refuseSave ? false : $this->pool->commit();
    }

    /** @param array<string> $tags */
    public function invalidateTags(array $tags): bool
    {
        return $this->refuseInvalidation ? false : $this->pool->invalidateTags($tags);
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->pool->getItem($key);
    }

    /** @param array<string> $keys */
    public function getItems(array $keys = []): iterable
    {
        return $this->pool->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->pool->clear($prefix);
    }

    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    /** @param array<string> $keys */
    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }
}
