<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\RedisCache;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Process\Process;

class StorageRedisModuleTest extends TestCase
{
    /**
     * @var Process
     */
    private static $process;

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

    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $server = 'localhost:6379';
        $cache = (new Injector(new StorageRedisModule($server), __DIR__ . '/tmp'))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(RedisCache::class, $cache);
    }

    public function testCacheNamespace()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $server = 'localhost:6379';
        $cache = (new Injector(new CacheVersionModule('1', new StorageRedisModule($server)), __DIR__ . '/tmp'))->getInstance(CacheProvider::class, Storage::class);
        assert($cache instanceof RedisCache);
        $namespace = $cache->getNamespace();
        $this->assertSame(':1', $namespace);
    }
}
