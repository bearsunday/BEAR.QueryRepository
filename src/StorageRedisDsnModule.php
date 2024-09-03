<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\RedisDsn;
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
    public function __construct(
        private readonly string $dsn,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /** @throws ReflectionException */
    protected function configure(): void
    {
        $this->bind()->annotatedWith(RedisDsn::class)->toInstance($this->dsn);
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(Local::class)->toConstructor(ApcuAdapter::class, ['namespace' => CacheNamespace::class])->in(Scope::SINGLETON);
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(Shared::class)->toConstructor(RedisAdapter::class, [
            'redisProvider' => 'redis',
            'namespace' => CacheNamespace::class,
        ]);
        $this->bind(ProviderInterface::class)->annotatedWith('redis')->to(RedisDsnProvider::class);
    }
}
