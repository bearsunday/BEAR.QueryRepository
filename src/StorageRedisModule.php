<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheEngine;
use BEAR\RepositoryModule\Annotation\Redis;
use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class StorageRedisModule extends AbstractModule
{
    /**
     * @var array
     */
    private $server;

    /**
     * @param string $server 'localhost:6379' {host}:{port}
     */
    public function __construct(string $server, AbstractModule $module = null)
    {
        $this->server = \explode(':', $server);
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith(Redis::class)->toInstance($this->server);
        $this->bind(CacheProvider::class)->annotatedWith(CacheEngine::class)->toProvider(StorageRedisCacheProvider::class);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(NamespacedCacheProvider::class)->in(Scope::SINGLETON);
    }
}
