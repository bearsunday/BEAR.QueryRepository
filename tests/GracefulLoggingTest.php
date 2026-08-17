<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function assert;
use function dirname;
use function json_encode;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;
use const JSON_UNESCAPED_SLASHES;

/**
 * Logging must never break cache behavior
 *
 * Since koriym/semantic-logger 0.9 the core logger is a total function: protocol
 * misuse (a LIFO violation, a session left unclosed) never throws — it is recorded
 * in-band as `semantic_logger_error` diagnostics. A broken logging session can
 * therefore no longer break cache reads/writes (and the dependency cascade).
 */
class GracefulLoggingTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testCacheWorksWhenLoggingSessionIsMisused(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // Misuse the shared logger between cache operations: open two scopes and
        // close the outer one first — a LIFO violation.
        $outer = $logger->open(new GetContext('page://self/misuse/outer'));
        $logger->open(new GetContext('page://self/misuse/inner'));
        $logger->close(new CacheMissContext('resource'), $outer);

        // Building the 3-level dependency chain must succeed despite the broken session.
        $ro = $resource->get('page://self/dep/level-one');
        $this->assertSame(200, $ro->code);
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);
        $this->assertArrayNotHasKey(Header::AGE, $ro->headers, 'first access is a miss (no Age header)');

        // A second access is served from cache: same stored ETag, an Age header proves the
        // stored entry was reused, and still no exception leaks out.
        $cached = $resource->get('page://self/dep/level-one');
        $this->assertSame($ro->headers[Header::ETAG], $cached->headers[Header::ETAG]);
        $this->assertArrayHasKey(Header::AGE, $cached->headers, 'second access is an observable cache hit');

        // The misuse did not vanish silently: it is recorded in-band as a diagnostic,
        // which validates against the core schemas bundled with koriym/semantic-logger.
        $tree = $this->flushAndValidateWithDiagnostics($logger);
        $diagnostic = self::eventContextJsonOf($tree, 'semantic_logger_error');
        $this->assertNotNull($diagnostic, 'the LIFO violation is recorded as a diagnostic');
        $this->assertStringContainsString('close_id_mismatch', $diagnostic);

        // Unbalanced closes must not drive the depth counter below zero: manual writes are
        // rooted by asking the logger whether the next open would be top-level, so a
        // negative depth would silently stop rooting them for the rest of the session.
        $logger->close(new CacheMissContext('resource'), 'no-such-open-id');
        assert($logger instanceof TopLevelAwareInterface);
        $this->assertTrue($logger->isTopLevel(), 'the session is back at the top level');
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
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // The cache pool is down: the read falls back to a live GET with a warning, not an exception.
        $warningCaught = false;
        set_error_handler(static function (int $errno) use (&$warningCaught): bool {
            if ($errno === E_USER_WARNING) {
                $warningCaught = true;

                return true; // swallow the cache-down warning
            }

            return false;
        });
        try {
            $ro = $resource->get('app://self/user', ['id' => 1]);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($warningCaught, 'the cache-down fallback warns (E_USER_WARNING) instead of throwing');
        $this->assertSame(200, $ro->code);

        $tree = $this->flushAndValidate($logger);
        // The outage is logged as cache_error, distinguishable from a cold miss...
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'a cache_error event marks the degraded cache layer');
        $this->assertStringContainsString('app://self/user', $error);
        $this->assertStringContainsString('"operation":"read"', $error, 'the failing side (the repository get) is recorded');
        $this->assertStringContainsString('cache server down', $error);
        $this->assertStringContainsString('"exceptionClass":"RuntimeException"', $error, 'the throwable class names the pool outage');
        // ...while the get scope still closes cache_miss: the pair (cache_error + cache_miss)
        // is an outage, a lone cache_miss is a cold cache.
        $close = self::closeContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($close, 'the get scope still closes cache_miss');
        $this->assertStringContainsString('"layer":"resource"', $close);
    }

    public function testCacheErrorRecordsTheClassOfAStoreSideFailureThatIsNotAnOutage(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                // The pools are healthy; rendering the view the store is about to save is what fails.
                $this->bind(RenderInterface::class)->to(FakeThrowingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $warningCaught = false;
        set_error_handler(static function (int $errno) use (&$warningCaught): bool {
            if ($errno === E_USER_WARNING) {
                $warningCaught = true;

                return true; // swallow the failed-store warning
            }

            return false;
        });
        try {
            $ro = $resource->get('app://self/user', ['id' => 1]);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($warningCaught, 'a failed store degrades to a warning, as a cache-down read does');
        $this->assertSame(200, $ro->code, 'the response is served even though it was never stored');

        $tree = $this->flushAndValidate($logger);
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the failed store is recorded, not swallowed');
        $this->assertStringContainsString('"operation":"write"', $error);
        // The class is what separates this from the cache-down case above: same context,
        // same operation, but a rendering bug instead of a pool outage.
        $this->assertStringContainsString('"exceptionClass":"BEAR\\\\QueryRepository\\\\FakeTemplateNotFound"', $error);
        $this->assertStringContainsString('template not found', $error);
    }

    public function testManualWritesCloseAsFailedWhenTheStoreThrows(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false, throwOnSave: true);
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($pool);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $repository = $injector->getInstance(QueryRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $ro = new Index();
        $ro->uri = new Uri('page://self/index');
        $ro->body = [];

        // A direct write has no interceptor to degrade it: the pool outage surfaces to the
        // caller. The scope it opened must then close as failed - a scope that closed
        // `stored` while the caller caught an exception is the log lying about the outcome.
        try {
            $repository->put($ro);
            $this->fail('expected the pool outage to surface from a direct write');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cache server down', $e->getMessage());
        }

        $tree = $this->flushAndValidate($logger);
        $close = self::closeContextJsonOf($tree, 'manual_store_result');
        $this->assertNotNull($close, 'the manual write scope closes even though the write threw');
        $this->assertStringContainsString('"result":"failed"', $close);
    }

    public function testDonutWriteFailureIsRecordedBeforeItPropagates(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                // Reads work (the GET must reach the write), only stores throw.
                $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false, throwOnSave: true);
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($pool);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // A donut write meeting a pool outage propagates (whether it should degrade like a
        // plain #[Cacheable] write is a behavior change this rebuild does not make) - but it
        // must be recorded in-band first. Without the event the scope shows a put_donut with
        // no saves and no reason: indistinguishable from an abort.
        set_error_handler(static fn (): bool => true); // the embedded #[Cacheable] comment degrades with a warning
        try {
            $resource->get('page://self/html/blog-posting');
            $this->fail('expected the pool outage to surface from the donut write');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cache server down', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $tree = $this->flushAndValidate($logger);
        // The embedded #[Cacheable] comment records its own degraded write failure, so the
        // assertion must name the page URI: the donut write's record, not the first match.
        $json = json_encode($tree, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringContainsString(
            '"uri":"page://self/html/blog-posting","operation":"write","error":"cache server down","exceptionClass":"RuntimeException"',
            $json,
            'the failed donut write is recorded in-band, on its own URI',
        );
        $close = self::closeContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($close, 'the get scope still closes');
    }
}
