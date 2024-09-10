<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * @SuppressWarnings UnusedFormalParameter
 * @codeCoverageIgnore
 */
final class NullTagAwareAdapter implements TagAwareAdapterInterface
{
    public function getItem(mixed $key): CacheItem
    {
        unset($key);
        $item = new CacheItem();
        $item->set(null);
        $item->tag('');

        return $item;
    }

    /** @inheritDoc */
    public function getItems(array $keys = []): iterable
    {
        return [];
    }

    public function clear(string $prefix = ''): bool
    {
        unset($prefix);

        return true;
    }

    /**
     * @param string $key
     *
     * @return bool
     *
     * @inheritDoc
     */
    public function hasItem($key)
    {
        return false;
    }

    /**
     * @param string $key
     *
     * @return bool
     *
     * @* @inheritDoc
     */
    public function deleteItem($key)
    {
        return true;
    }

    /** @inheritDoc */
    public function deleteItems(array $keys): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function save(CacheItemInterface $item): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function commit(): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function invalidateTags(array $tags): bool
    {
        return true;
    }
}
