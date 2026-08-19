<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\PoolErrorLogger;
use BEAR\RepositoryModule\Annotation\EtagPool;
use Memcached;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Ray\Di\AbstractModule;
use Ray\Di\InjectionPoints;
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
    #[Override]
    protected function configure(): void
    {
        $this->bind(LoggerInterface::class)->annotatedWith('poolError')->to(PoolErrorLogger::class);
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->toConstructor(
            MemcachedAdapter::class,
            [
                'namespace' => CacheNamespace::class,
                'clientProvider' => 'memcached',
            ],
            // A dedicated ETag store that is down must reach the cache log too,
            // or a failed validator read is an ordinary miss.
            (new InjectionPoints())->addMethod('setLogger', 'poolError'),
        );
        $this->bind()->annotatedWith(MemcacheConfig::class)->toInstance($this->memcacheServer);
        $this->bind(MemcachedProvider::class);
        $this->bind(Memcached::class)->toProvider(MemcachedProvider::class);
    }
}
