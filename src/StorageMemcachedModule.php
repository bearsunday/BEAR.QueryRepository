<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Memcache;
use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;

class StorageMemcachedModule extends AbstractModule
{
    /**
     * @var array
     */
    private $servers;

    public function __construct($servers, AbstractModule $module = null)
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
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(StorageMemcachedCacheProvider::class);
    }
}
