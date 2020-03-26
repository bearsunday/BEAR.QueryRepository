<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheVersion;
use BEAR\RepositoryModule\Annotation\Redis;
use BEAR\Resource\Annotation\AppName;
use Doctrine\Common\Cache\RedisCache;
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
     * @var string
     */
    private $appName;

    /**
     * @var string
     */
    private $version;

    /**
     * @Redis("server")
     * @AppName("appName")
     * @CacheVersion("version")
     */
    public function __construct(array $server, string $appName = '', string $version = '')
    {
        $this->server = $server;
        $this->appName = $appName;
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $redis = new \Redis();
        $host = $this->server[0];
        $port = $this->server[1];
        $redis->connect($host, (int) $port);
        $redisCache = new RedisCache();
        $redisCache->setRedis($redis);
        $redisCache->setNamespace($this->appName . $this->version);

        return $redisCache;
    }
}
