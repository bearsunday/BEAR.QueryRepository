<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface as HttpCacheInterfaceAlias;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

use function assert;
use function dirname;
use function serialize;
use function unserialize;

/** @requires extension redis */
class DonutCommandRedisCacheTest extends DonutCommandInterceptorTest
{
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
        $module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379'));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(RepositoryLoggerInterface::class);
        $httpCache = $injector->getInstance(HttpCacheInterfaceAlias::class);
        $unserializedHttpCache = unserialize(serialize($httpCache));
        assert($unserializedHttpCache instanceof HttpCacheInterfaceAlias);
        $this->httpCache = $unserializedHttpCache;
    }
}
