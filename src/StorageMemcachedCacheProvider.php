<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheVersion;
use BEAR\RepositoryModule\Annotation\Memcache;
use BEAR\Resource\Annotation\AppName;
use Doctrine\Common\Cache\MemcachedCache;
use Ray\Di\ProviderInterface;

class StorageMemcachedCacheProvider implements ProviderInterface
{
    /**
     * memcached server list
     *
     * @var array
     */
    private $servers;

    /**
     * @var string
     */
    private $appName;

    /**
     * @var string
     */
    private $version;

    /**
     * @Memcache("servers")
     * @AppName("appName")
     * @CacheVersion("version")
     *
     * @see http://php.net/manual/en/memcached.addservers.php
     */
    public function __construct(array $servers, string $appName = '', string $version = '')
    {
        $this->servers = $servers;
        $this->appName = $appName;
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $memcachedCache = new MemcachedCache();
        $memcache = new \Memcached();
        $memcache->addServers($this->servers);
        $memcachedCache->setMemcached($memcache);
        $memcachedCache->setNamespace($this->appName . $this->version);

        return $memcachedCache;
    }
}
