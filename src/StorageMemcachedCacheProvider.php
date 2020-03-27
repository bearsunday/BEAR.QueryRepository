<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Memcache;
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
     * @Memcache("servers")
     *
     * @see http://php.net/manual/en/memcached.addservers.php
     */
    public function __construct(array $servers)
    {
        $this->servers = $servers;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $memcachedCache = new MemcachedCache;
        $memcache = new \Memcached;
        $memcache->addServers($this->servers);
        $memcachedCache->setMemcached($memcache);

        return $memcachedCache;
    }
}
