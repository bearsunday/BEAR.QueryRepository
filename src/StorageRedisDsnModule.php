<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\MarshallerOptions;
use BEAR\RepositoryModule\Annotation\RedisDsn;
use BEAR\RepositoryModule\Annotation\RedisDsnOptions;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\ProviderInterface;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use ReflectionException;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

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
     * @param string                                                   $dsn                Redis DSN
     * @param array<string, bool|int|string|array<string, mixed>|null> $options            Redis DSN Options
     * @param int                                                      $defaultLifetime    Default lifetime for cache items in seconds
     * @param array<string, mixed>                                     $marshallingOptions Marshalling options for compression/encryption
     */
    public function __construct(
        private readonly string $dsn,
        private readonly array $options = [],
        private readonly int $defaultLifetime = 0,
        private readonly array $marshallingOptions = [],
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /** @throws ReflectionException */
    #[Override]
    protected function configure(): void
    {
        $this->bind()->annotatedWith(RedisDsn::class)->toInstance($this->dsn);
        $this->bind()->annotatedWith(RedisDsnOptions::class)->toInstance($this->options);
        $this->bind()->annotatedWith(MarshallerOptions::class)->toInstance($this->marshallingOptions);
        $this->bind(ProviderInterface::class)->annotatedWith('redis')->to(RedisDsnProvider::class);
        $this->bind()->annotatedWith('redis')->toProvider(RedisDsnProvider::class);
        $this->bind(MarshallerInterface::class)->toProvider(MarshallerProvider::class);
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            RedisTagAwareAdapter::class,
            [
                'redis' => 'redis',
                'namespace' => CacheNamespace::class,
                'defaultLifetime' => (string) $this->defaultLifetime,
                'marshaller' => MarshallerInterface::class,
            ],
        );
    }
}
