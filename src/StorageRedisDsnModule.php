<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\RedisDsn;
use BEAR\RepositoryModule\Annotation\RedisDsnOptions;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\Di\ProviderInterface;
use Ray\Di\Scope;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use Ray\PsrCacheModule\Annotation\Local;
use Ray\PsrCacheModule\Annotation\Shared;
use Ray\PsrCacheModule\ApcuAdapter;
use Ray\PsrCacheModule\RedisAdapter;
use ReflectionException;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Provides ResourceStorageInterface and derived bindings
 * *
 * * The following bindings are provided:
 * *
 * * CacheItemPoolInterface-EtagPool::class
 * *
 * * The following module are installed:
 * *
 * * Psr6RedisModule
 * Provides CacheItemPool and derived bindings
 *
 * [...]
 *
 * The following bindings are provided:
 *
 *  ::RedisDsn
 *  CacheItemPoolInterface::Local
 *  CacheItemPoolInterface::Shared
 */
final class StorageRedisDsnModule extends AbstractModule
{
    /**
     * Redis configuration DSN
     *
     * @param string                                                   $dsn     Redis DSN
     * @param array<string, bool|int|string|array<string, mixed>|null> $options Redis DSN Options
     */
    public function __construct(
        private readonly string $dsn,
        private readonly array $options = [],
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /** @throws ReflectionException */
    protected function configure(): void
    {
        $this->bind()->annotatedWith(RedisDsn::class)->toInstance($this->dsn);
        $this->bind()->annotatedWith(RedisDsnOptions::class)->toInstance($this->options);
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(Shared::class)->toConstructor(RedisAdapter::class, [
            'redisProvider' => 'redis',
            'namespace' => CacheNamespace::class,
        ]);
        $this->bind(ProviderInterface::class)->annotatedWith('redis')->to(RedisDsnProvider::class);
        $this->bind()->annotatedWith('redis')->toProvider(RedisDsnProvider::class);
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            RedisTagAwareAdapter::class,
            [
                'redis' => 'redis',
                'namespace' => CacheNamespace::class,
            ],
        );
    }
}
