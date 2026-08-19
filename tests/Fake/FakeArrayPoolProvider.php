<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * A pool provider that survives serialization
 *
 * The helper in ResourceStorageTest builds its providers as anonymous classes, which PHP refuses
 * to serialize - so a round-trip test needs a named one. It holds no state: the pool is built on
 * demand, which is what a provider does in a compiled graph anyway.
 *
 * @implements ProviderInterface<TagAwareAdapterInterface>
 */
final class FakeArrayPoolProvider implements ProviderInterface
{
    public function get(): TagAwareAdapterInterface
    {
        return new TagAwareAdapter(new ArrayAdapter());
    }
}
