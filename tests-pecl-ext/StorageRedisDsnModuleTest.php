<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Ray\PsrCacheModule\MemcachedAdapter;
use Ray\PsrCacheModule\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Process\Process;

use function getenv;
use function usleep;

class StorageRedisDsnModuleTest extends TestCase
{
    /** @var Process */
    private static $process;

    /** @var string */
    private $dsn;

    public static function setUpBeforeClass(): void
    {
        self::$process = new Process(['redis-server']);
        self::$process->disableOutput();
        self::$process->start();
        usleep(1000000); //wait for server to get going
    }

    public static function tearDownAfterClass(): void
    {
        self::$process->stop(1);
    }

    protected function setUp(): void
    {
        $this->dsn = getenv('REDIS_SERVER') ? getenv('REDIS_SERVER') : 'localhost:6379';
    }

    public function testNew(): void
    {
        // @see https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html
        $dsn = 'redis://localhost:6379';
        $options = ['timeout' => 10];
        $module = new QueryRepositoryModule();
        $module->override(new StorageRedisDsnModule($dsn, $options));
        $injector = (new Injector($module, __DIR__ . '/tmp'));
        $redisAdapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(RedisTagAwareAdapter::class, $redisAdapter);
        $etagAdapter = $injector->getInstance(TagAwareAdapterInterface::class, EtagPool::class);
        $this->assertInstanceOf(NullTagAwareAdapter::class, $etagAdapter);
        $resourceStorage = $injector->getInstance(ResourceStorageInterface::class);
    }
}
