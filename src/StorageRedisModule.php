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

class StorageRedisModule extends AbstractModule
{
    /**
     * @var array
     */
    private $server;

    /**
     * @param string              $server {host}:{port}
     * @param AbstractModule|null $module
     */
    public function __construct($server, AbstractModule $module = null)
    {
        $this->server = explode(':', $server);
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith('redis_server')->toInstance($this->server);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(StorageRedisCacheProvider::class);
    }
}
