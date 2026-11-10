<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\PoolErrorLogger;
use BEAR\RepositoryModule\Annotation\EtagPool;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Ray\Di\AbstractModule;
use Ray\Di\InjectionPoints;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use Ray\PsrCacheModule\MemcachedAdapter;
use Ray\PsrCacheModule\MemcachedProvider;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function array_map;
use function explode;

/**
 * Provides TagAwareAdapterInterface-EtagPool::class backed by Memcached servers of its own
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
    #[Override]
    protected function configure(): void
    {
        $this->bind(LoggerInterface::class)->annotatedWith('poolError')->to(PoolErrorLogger::class);
        // Its own provider: an unqualified MemcacheConfig would also re-point the ResourceObjectPool.
        $this->bind(MemcachedProvider::class)->annotatedWith(EtagPool::class)->toInstance(new MemcachedProvider($this->memcacheServer));
        // A dedicated ETag store that is down must reach the cache log too,
        // or a failed validator read is an ordinary miss.
        $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->toConstructor(
            MemcachedAdapter::class,
            ['provider' => EtagPool::class, 'namespace' => CacheNamespace::class],
            (new InjectionPoints())->addMethod('setLogger', 'poolError'),
        );
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->toConstructor(
            MemcachedAdapter::class,
            ['provider' => EtagPool::class, 'namespace' => CacheNamespace::class],
            (new InjectionPoints())->addMethod('setLogger', 'poolError'),
        );
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toConstructor(
            TagAwareAdapter::class,
            ['itemsPool' => EtagPool::class],
            (new InjectionPoints())->addMethod('setLogger', 'poolError'),
        );
    }
}
