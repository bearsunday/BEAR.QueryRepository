<?php

namespace BEAR\QueryRepository;

use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Error cache driver
 */
class FakeErrorCache implements AdapterInterface
{
    public function getItem($key)
    {
        throw new RuntimeException();
    }

    public function getItems(array $keys = array())
    {
    }

    public function hasItem($key)
    {
    }

    public function clear(string $prefix = '')
    {
    }

    public function deleteItem($key)
    {
    }

    public function deleteItems(array $keys)
    {
    }

    public function save(CacheItemInterface $item)
    {
    }

    public function saveDeferred(CacheItemInterface $item)
    {
    }

    public function commit()
    {
    }
}
