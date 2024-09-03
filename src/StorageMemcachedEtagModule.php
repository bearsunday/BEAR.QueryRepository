<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use Memcached;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use Ray\PsrCacheModule\Annotation\MemcacheConfig;
use Ray\PsrCacheModule\MemcachedAdapter;
use Ray\PsrCacheModule\MemcachedProvider;

use function array_map;
use function explode;

/**
 * Memcached EtagPool module
 */
final class StorageMemcachedEtagModule extends AbstractModule
{
    /** @var list<list<string>> */
    private readonly array $memcacheServer;

    public function __construct(
        string $memcacheServer,
        AbstractModule|null $module = null,
    ) {
        $this->memcacheServer = array_map(static fn ($memcacheServer) => explode(':', $memcacheServer), explode(',', $memcacheServer));

        parent::__construct($module);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->toConstructor(MemcachedAdapter::class, [
            'namespace' => CacheNamespace::class,
            'clientProvider' => 'memcached',
        ]);
        $this->bind()->annotatedWith(MemcacheConfig::class)->toInstance($this->memcacheServer);
        $this->bind(MemcachedProvider::class);
        $this->bind(Memcached::class)->toProvider(MemcachedProvider::class);
    }
}
