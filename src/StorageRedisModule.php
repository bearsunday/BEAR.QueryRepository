<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Redis;
use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;

class StorageRedisModule extends AbstractModule
{
    /**
     * @var array
     */
    private $server;

    /**
     * @param string $server {host}:{port} format
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
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(StorageRedisCacheProvider::class);
    }
}
