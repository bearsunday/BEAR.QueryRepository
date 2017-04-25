<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\MemcachedCache;
use Ray\Di\Di\Named;
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
     * @Named("servers=memcached_servers")
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
        $memcachedCache = new MemcachedCache();
        $memcache = new \Memcached();
        $memcache->addServers($this->servers);
        $memcachedCache->setMemcached($memcache);

        return $memcachedCache;
    }
}
