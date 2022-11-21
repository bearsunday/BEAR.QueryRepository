<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use Ray\PsrCacheModule\Annotation\RedisInstance;
use Ray\PsrCacheModule\Psr6RedisModule;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Provides ResourceStorageInterface and derived bindings
 *
 * The following bindings are provided:
 *
 * CacheItemPoolInterface-EtagPool::class
 *
 * The following module are installed:
 *
 * Psr6RedisModule
 */
final class StorageRedisModule extends AbstractModule
{
    /** @param string $server 'localhost:6379' {host}:{port} */
    public function __construct(
        private string $server,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->install(new Psr6RedisModule($this->server));
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->toConstructor(RedisAdapter::class, [
            'redis' => RedisInstance::class,
            'namespace' => CacheNamespace::class,
        ]);
    }
}
