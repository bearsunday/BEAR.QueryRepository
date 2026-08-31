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
use function bin2hex;
use function dirname;
use function random_bytes;
use function serialize;
use function unserialize;

/** @requires extension redis */
class DonutCommandRedisCacheTest extends DonutCommandInterceptorTest
{
    use RequiresRedisServerTrait;

    private TagAwareAdapterInterface|null $roPool = null;

    protected function setUp(): void
    {
        // Before the probe: the parent's tearDown validates the log it assigns here, and it runs
        // for a skipped method too.
        parent::setUp();

        self::skipWithoutRedisServer();

        // Override with Redis-backed instances. parent::setUp() assigns the same
        // properties from a non-Redis module, so it must run first.
        // StorageRedisDsnModule binds the RedisTagAwareAdapter pools consumed by
        // ResourceStorage; the deprecated StorageRedisModule does not.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new StorageRedisDsnModule(self::redisDsn()));
        // Namespace the pool per test method: the adapter is unnamespaced by default, so its
        // clear() reaches every key in the database, and a fresh namespace is cold by construction
        // - which is what the inherited tests need from a server that outlives the process.
        $module->override(new CacheVersionModule(bin2hex(random_bytes(8))));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->roPool = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $httpCache = $injector->getInstance(HttpCacheInterfaceAlias::class);
        $unserializedHttpCache = unserialize(serialize($httpCache));
        assert($unserializedHttpCache instanceof HttpCacheInterfaceAlias);
        $this->httpCache = $unserializedHttpCache;
    }

    protected function tearDown(): void
    {
        // Drop this method's namespace: a new one every method would otherwise leave its keys
        // behind on a server that outlives the run.
        $this->roPool?->clear();
        $this->roPool = null;

        parent::tearDown();
    }
}
