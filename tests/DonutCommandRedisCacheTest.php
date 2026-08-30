<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface as HttpCacheInterfaceAlias;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function assert;
use function dirname;
use function serialize;
use function unserialize;

/** @requires extension redis */
class DonutCommandRedisCacheTest extends DonutCommandInterceptorTest
{
    use RequiresRedisServerTrait;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::skipWithoutRedisServer();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Override with Redis-backed instances. parent::setUp() assigns the same
        // properties from a non-Redis module, so it must run first.
        // StorageRedisDsnModule binds the RedisTagAwareAdapter pools consumed by
        // ResourceStorage; the deprecated StorageRedisModule does not.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new StorageRedisDsnModule(self::redisDsn()));
        $injector = new Injector($module, __DIR__ . '/tmp');
        // The parent's in-memory pool is new in every test method, this Redis server is not:
        // drop what the previous method stored so each inherited test starts from a cold cache.
        $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class)->clear();
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $httpCache = $injector->getInstance(HttpCacheInterfaceAlias::class);
        $unserializedHttpCache = unserialize(serialize($httpCache));
        assert($unserializedHttpCache instanceof HttpCacheInterfaceAlias);
        $this->httpCache = $unserializedHttpCache;
    }
}
