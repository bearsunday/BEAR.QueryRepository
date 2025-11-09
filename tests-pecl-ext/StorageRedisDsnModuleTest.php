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

/**
 * @requires extension redis
 */
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
        $maxAttempts = 10;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            if (@fsockopen('localhost', 6379)) {
                return;
            }
            usleep(100000); // 100ms
            $attempt++;
        }
        throw new \RuntimeException('Redis server failed to start');
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
        $this->assertNull($etagAdapter);
        $resourceStorage = $injector->getInstance(ResourceStorageInterface::class);
        $this->assertInstanceOf(ResourceStorageInterface::class, $resourceStorage);
    }
}
