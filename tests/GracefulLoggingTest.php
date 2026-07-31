<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * Logging must never break cache behavior
 *
 * Even when the underlying semantic logger throws on every call, SafeSemanticLogger
 * swallows the failure so cache reads/writes (and the dependency cascade) keep working.
 */
class GracefulLoggingTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testCacheWorksWhenLoggerAlwaysThrows(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->toInstance(
                    new SafeSemanticLogger(new ThrowingSemanticLogger()),
                );
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        // Building the 3-level dependency chain must succeed despite the failing logger.
        $ro = $resource->get('page://self/dep/level-one');
        $this->assertSame(200, $ro->code);
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);

        // A second access is served from cache: same stored ETag, and still no exception leaks out.
        $cached = $resource->get('page://self/dep/level-one');
        $this->assertSame($ro->headers[Header::ETAG], $cached->headers[Header::ETAG]);
    }

    public function testCacheErrorIsLoggedWhenCacheServerIsDown(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new TagAwareAdapter(new FakeErrorCache()));
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // The cache pool is down: the read falls back to a live GET with a warning, not an exception.
        set_error_handler(static function (int $errno): bool {
            return $errno === E_USER_WARNING; // swallow the cache-down warning
        });
        try {
            $ro = $resource->get('app://self/user', ['id' => 1]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $ro->code);

        $tree = $this->flushAndValidate($logger);
        // The outage is logged as cache_error, distinguishable from a cold miss...
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'a cache_error event marks the degraded cache layer');
        $this->assertStringContainsString('app://self/user', $error);
        $this->assertStringContainsString('cache server down', $error);
        // ...while the get scope still closes cache_miss: the pair (cache_error + cache_miss)
        // is an outage, a lone cache_miss is a cold cache.
        $close = self::closeContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($close, 'the get scope still closes cache_miss');
        $this->assertStringContainsString('"layer":"resource"', $close);
    }
}
