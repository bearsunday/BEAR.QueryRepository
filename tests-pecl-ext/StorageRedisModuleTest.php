<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Process\Process;
use function getenv;

class StorageRedisModuleTest extends TestCase
{
    /**
     * @var Process
     */
    private static $process;

    /** @var string */
    private $server;

    public static function setUpBeforeClass() : void
    {
        self::$process = new Process(['redis-server']);
        self::$process->disableOutput();
        self::$process->start();
        usleep(1000000); //wait for server to get going
    }

    public static function tearDownAfterClass() : void
    {
        self::$process->stop(1);
    }

    protected function setUp() : void
    {
        $this->server = getenv('REDIS_SERVER') ? getenv('REDIS_SERVER') : 'localhost:6379';
    }

    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $cache = (new Injector(new StorageRedisModule($this->server), __DIR__ . '/tmp'))->getInstance(CacheItemPoolInterface::class, Shared::class);
        $this->assertInstanceOf(RedisAdapter::class, $cache);
    }

    public function testNewRedisMemchache()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $memcacheServers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $injector = new Injector(new StorageRedisMemcachedModule($this->server, $memcacheServers), __DIR__ . '/tmp');
        $cache = $injector->getInstance(CacheItemPoolInterface::class, Shared::class);
        $this->assertInstanceOf(RedisAdapter::class, $cache);
        $etagPool = $injector->getInstance(CacheItemPoolInterface::class, EtagPool::class);
        $this->assertInstanceOf(MemcachedAdapter::class, $etagPool);
    }
}
