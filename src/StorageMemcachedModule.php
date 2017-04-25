<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

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
        $this->servers = array_map(function ($serverString) {
            return explode(':', $serverString);
        }, explode(',', $servers));
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith('memcached_servers')->toInstance($this->servers);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(StorageMemcachedCacheProvider::class);
    }
}
