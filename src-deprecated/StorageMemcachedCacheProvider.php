<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Memcache;
use Doctrine\Common\Cache\MemcachedCache;
use Memcached;
use Ray\Di\ProviderInterface;

/**
 * @deprecated
 */
class StorageMemcachedCacheProvider implements ProviderInterface
{
    /**
     * memcached server list
     *
     * @var array<string>
     */
    private $servers;

    /**
     * @param array<string> $servers
     *
     * @Memcache("servers")
     * @see http://php.net/manual/en/memcached.addservers.php
     */
    #[Memcache('servers')]
    public function __construct(array $servers)
    {
        $this->servers = $servers;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        /** @psalm-suppress DeprecatedClass */
        $memcachedCache = new MemcachedCache();
        $memcache = new Memcached();
        $memcache->addServers($this->servers);
        $memcachedCache->setMemcached($memcache);

        return $memcachedCache;
    }
}
