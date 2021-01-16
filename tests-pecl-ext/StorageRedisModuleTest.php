<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\RedisCache;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
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
        $cache = (new Injector(new StorageRedisModule($this->server), __DIR__ . '/tmp'))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(RedisCache::class, $cache);
    }

    public function testCacheNamespace()
    {
        $cache = (new Injector(new CacheVersionModule('1', new StorageRedisModule($this->server)), __DIR__ . '/tmp'))->getInstance(CacheProvider::class, Storage::class);
        assert($cache instanceof RedisCache);
        $namespace = $cache->getNamespace();
        $this->assertSame(':1', $namespace);
    }
}
