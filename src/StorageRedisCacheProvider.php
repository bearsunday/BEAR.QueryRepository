<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\RedisCache;
use Ray\Di\Di\Named;
use Ray\Di\ProviderInterface;

class StorageRedisCacheProvider implements ProviderInterface
{
    /**
     * redis server
     *
     * @var array
     */
    private $server;

    /**
     * @Named("redis_server")
     */
    public function __construct(array $server)
    {
        $this->server = $server;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $redis = new \Redis();
        $host = $this->server[0];
        $port = $this->server[1];
        $redis->connect($host, $port);
        $redisCache = new RedisCache();
        $redisCache->setRedis($redis);

        return $redisCache;
    }
}
