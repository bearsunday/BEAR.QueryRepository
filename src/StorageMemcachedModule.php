<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheEngine;
use BEAR\RepositoryModule\Annotation\Memcache;
use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class StorageMemcachedModule extends AbstractModule
{
    /**
     * @var array
     */
    private $servers;

    /**
     * @param string $servers 'mem1.domain.com:11211:33,mem2.domain.com:11211:67' {host}:{port}:{weight}
     */
    public function __construct(string $servers, AbstractModule $module = null)
    {
        $this->servers = \array_map(function ($serverString) {
            return \explode(':', $serverString);
        }, \explode(',', $servers));
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith(Memcache::class)->toInstance($this->servers);
        $this->bind(CacheProvider::class)->annotatedWith(CacheEngine::class)->toProvider(StorageMemcachedCacheProvider::class);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(NamespacedCacheProvider::class)->in(Scope::SINGLETON);
    }
}
