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

final class StorageRedisModule extends AbstractModule
{
    /** @var string */
    private $server;

    /**
     * @param string $server 'localhost:6379' {host}:{port}
     */
    public function __construct(string $server, ?AbstractModule $module = null)
    {
        $this->server = $server;
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
