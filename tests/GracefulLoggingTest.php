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
use ErrorException;
use FakeVendor\HelloWorld\Resource\App\FlakyHole;
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
use Twig\Error\Error as TwigError;

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

    /**
     * `FlakyHole::$failureCount` is mutable static state (PHP has no other way to make a
     * resource's own onGet() behavior controllable per test) - reset unconditionally so a
     * test that leaves it non-zero can never leak into a later, unrelated test.
     */
    protected function tearDown(): void
    {
        FlakyHole::$failureCount = 0;

        parent::tearDown();
    }

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

    public function testARenderFailureOnTheWritePathKeepsTravelling(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                // The pools are healthy; rendering the view this entry stores is what fails.
                $this->bind(RenderInterface::class)->to(FakeThrowingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // A `type: 'view'` entry: the render is the write. A page that cannot render is not a slow
        // page, it is a page that does not exist, so this failure is recorded and then travels -
        // only what the store itself raised degrades (issue #190).
        try {
            $resource->get('page://self/html/like');
            $this->fail('expected the render failure to surface');
        } catch (FakeTemplateNotFound $e) {
            $this->assertStringContainsString('template not found', $e->getMessage());
        }

        $tree = $this->flushAndValidate($logger);
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the failure is recorded before it travels');
        $this->assertStringContainsString('page://self/html/like', $error);
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

    public function testDonutWriteFailureIsRecordedAndTheRenderedPageIsStillServed(): void
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

        // A donut write meeting a pool outage degrades like a plain #[Cacheable] write: the page
        // was rendered correctly, so it is served and the failure is recorded in-band. Without the
        // event the scope would show a put_donut with no saves and no reason - indistinguishable
        // from an abort (issue #190).
        set_error_handler(static fn (): bool => true); // both writes degrade with a warning
        try {
            $ro = $resource->get('page://self/html/blog-posting');
            $this->assertSame(200, $ro->code, 'the rendered page is served even though nothing was stored');
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

    /**
     * A render that throws must never bake an empty donut template into the store - that
     * would serve an empty page to every later visitor (since 1.17.0). `ResourceDonut::create()`
     * renders with the non-swallowing `toString()`, and the hole is wrapped the same way
     * (`DonutRequest::__toString()`), so the Throwable travels to the caller as a clean,
     * catchable exception - the write is refused (and recorded) as a side effect of nothing
     * past the render ever running, not a caught, degraded failure. (Verified by execution:
     * this is actually BETTER than the same hole embedded outside `#[DonutCache]`, where the
     * raw `AbstractRequest::__toString()` swallows it into a fatal E_USER_ERROR instead.)
     */
    public function testDonutWriteLetsAHoleThrowTravelAndStoresNothing(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        try {
            $resource->get('page://self/html/donut-cache-with-throwing-hole');
            $this->fail('expected the hole throw to travel to the caller');
        } catch (TwigError $e) {
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
            $this->assertSame('the hole blew up mid-render', $e->getPrevious()->getMessage());
        }

        $tree = $this->flushAndValidate($logger);
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the aborted render is recorded before it travels');
        $this->assertStringContainsString('"operation":"write"', $error);

        // Independent of what a later request happens to do: directly proves nothing was
        // stored, rather than inferring it from a second request's behavior alone.
        $resourceStorage = $injector->getInstance(ResourceStorageInterface::class);
        $uri = new Uri('page://self/html/donut-cache-with-throwing-hole');
        $this->assertNull($resourceStorage->getDonut($uri), 'nothing was stored after the aborted render');

        // If the bug were present, this second request would be a cache HIT serving the
        // empty template the first request's write would otherwise have stored, instead of
        // throwing again.
        try {
            $resource->get('page://self/html/donut-cache-with-throwing-hole');
            $this->fail('expected the second request to also throw, not read a cached empty template');
        } catch (TwigError $e) {
            $this->assertSame('the hole blew up mid-render', $e->getPrevious()?->getMessage());
        }
    }

    /**
     * The same holds when the composing page's own template throws, outside any hole -
     * `$ro->toString()` in `ResourceDonut::create()` renders the page itself too.
     */
    public function testDonutWriteLetsAPageTemplateThrowTravelAndStoresNothing(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(RenderInterface::class)->to(FakeThrowingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        try {
            $resource->get('page://self/html/plain-donut-hole');
            $this->fail('expected the page template throw to travel to the caller');
        } catch (FakeTemplateNotFound $e) {
            $this->assertSame('template not found: user.html.twig', $e->getMessage());
        }

        $tree = $this->flushAndValidate($logger);
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the aborted render is recorded before it travels');
        $this->assertStringContainsString('"operation":"write"', $error);

        $resourceStorage = $injector->getInstance(ResourceStorageInterface::class);
        $uri = new Uri('page://self/html/plain-donut-hole');
        $this->assertNull($resourceStorage->getDonut($uri), 'nothing was stored after the aborted render');
    }

    /**
     * The guard never inspects a warning - it just lets `toString()` propagate a genuine
     * Throwable unwrapped. A page that merely emits an unrelated warning during render and
     * then renders successfully has nothing to propagate, so it is stored normally.
     */
    public function testDonutWriteStoresAPageThatEmitsAnUnrelatedWarningButRendersFine(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(RenderInterface::class)->to(FakeWarningEmittingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        set_error_handler(static fn (): bool => true); // let the unrelated warning pass quietly
        try {
            $ro = $resource->get('page://self/html/plain-donut-hole');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('rendered-ok', (string) $ro->view);

        $resourceStorage = $injector->getInstance(ResourceStorageInterface::class);
        $uri = new Uri('page://self/html/plain-donut-hole');
        $this->assertNotNull($resourceStorage->getDonut($uri), 'a page that renders fine despite an unrelated warning is stored');
    }

    /**
     * Fable's CRITICAL finding against the previous (warning-regex) design: an app with a
     * warning->exception converter (Whoops, Symfony ErrorHandler, Sentry) turns ANY warning
     * during render into a real exception. This design never installs a handler of its own
     * and never inspects a warning, so that converted exception is just an ordinary Throwable
     * `toString()` propagates - behaving identically to the same render with NO cache
     * attribute at all (not merely a different attribute like `#[Cacheable]`), and
     * identically on every later request (never "refused forever").
     */
    public function testAWarningToExceptionConverterBehavesIdenticallyWithAndWithoutDonutCache(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(RenderInterface::class)->to(FakeWarningEmittingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        $converter = static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        };

        set_error_handler($converter);
        try {
            // No cache attribute: $resource->get() alone does not render (onGet() only) -
            // the render happens later, the same place HttpResponder::getOutput() triggers
            // it (`$ro->toString()`), so that call is what this is compared against.
            $resource->get('page://self/html/plain-no-cache-attribute')->toString();
            $this->fail('expected the converted warning to surface for the no-cache-attribute page');
        } catch (ErrorException $e) {
            $this->assertSame('an unrelated notice during render', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        // Twice, to show the donut page is not treated worse (or better) than the plain
        // page, and not treated worse (or better) on a later request than on the first.
        for ($i = 0; $i < 2; $i++) {
            set_error_handler($converter);
            try {
                $resource->get('page://self/html/plain-donut-hole'); // #[DonutCache]
                $this->fail('expected the converted warning to surface identically for the donut page');
            } catch (ErrorException $e) {
                $this->assertSame('an unrelated notice during render', $e->getMessage(), 'identical to the no-#[DonutCache] behavior, every time');
            } finally {
                restore_error_handler();
            }
        }
    }

    /**
     * HIT refresh, transient hole failure: `ResourceDonut::refresh()`'s own re-fetch hits a
     * hole that fails once, degrades (the reviewed-and-kept CacheStoreFailure conversion),
     * and falls back to a full fresh render - which succeeds because the failure already
     * consumed itself. The response must be the fresh render's FULL body, not empty/partial.
     */
    public function testHitRefreshFallsBackToAFreshRenderWhenAHoleTransientlyFails(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        FlakyHole::$failureCount = 0;
        $first = $resource->get('page://self/html/donut-cache-with-flaky-hole');
        $this->assertSame(200, $first->code);
        $this->assertStringContainsString('healthy', (string) $first->view);

        FlakyHole::$failureCount = 1;
        set_error_handler(static fn (): bool => true); // the fallback degrade still warns
        try {
            $second = $resource->get('page://self/html/donut-cache-with-flaky-hole');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $second->code);
        $this->assertStringContainsString(
            'healthy',
            (string) $second->view,
            'the fresh render\'s full body - not empty, and not the raw AbstractRequest a bare proceed() fallback would leave unrendered',
        );
    }

    /**
     * HIT refresh, persistent hole failure: both the re-fetch and the fresh-render fallback
     * hit a still-broken hole, so the request fails exactly as the hole's own URI does when
     * requested directly with no donut composition at all.
     */
    public function testHitRefreshFailsTheSameWayAsNoDonutCacheWhenAHoleIsPersistentlyBroken(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        FlakyHole::$failureCount = 0;
        $resource->get('page://self/html/donut-cache-with-flaky-hole'); // caches a good template

        FlakyHole::$failureCount = 1000; // outlasts both the re-fetch and the fallback render
        set_error_handler(static fn (): bool => true); // the degraded-read warning still fires
        $threw = false;
        try {
            $resource->get('page://self/html/donut-cache-with-flaky-hole');
        } catch (TwigError $e) {
            $threw = true;
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
            $this->assertSame('the hole is currently broken', $e->getPrevious()->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($threw, 'expected the persistently broken hole to fail the request, same as without the cache attribute');

        FlakyHole::$failureCount = 1;
        $threw = false;
        try {
            $resource->get('app://self/flaky-hole'); // the hole's own URI, no donut composition
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertSame('the hole is currently broken', $e->getMessage());
        }

        $this->assertTrue($threw, 'expected the same failure requesting the hole directly');
    }

    public function testAValueEntryIsStoredWithoutRenderingTheResource(): void
    {
        // An app resource has no template under an html renderer, so any render attempt throws.
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(RenderInterface::class)->to(FakeThrowingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $warned = false;
        set_error_handler(static function (int $errno) use (&$warned): bool {
            $warned = $warned || $errno === E_USER_WARNING;

            return true;
        });
        try {
            $ro = $resource->get('app://self/user', ['id' => 1]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $ro->code);
        $this->assertFalse($warned, 'a value entry has no reason to render, so nothing degraded');

        $tree = $this->flushAndValidate($logger);
        $this->assertNull(self::eventContextJsonOf($tree, 'cache_error'), 'no write failure to record');
        $saved = self::eventContextJsonOf($tree, 'save_value');
        $this->assertNotNull($saved, 'the value was stored');
        $this->assertStringContainsString('"saved":true', $saved);
    }

    public function testDonutReadFallsBackToALiveRenderWhenTheStoreIsDown(): void
    {
        // The same contract as the `#[Cacheable]` read, on the donut path: a pool that throws
        // costs latency and a warning, not the page. The two interceptors have separate code for
        // it, and only one of them was covered - which is how a degrade path rots.
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

        $warningCaught = false;
        set_error_handler(static function (int $errno) use (&$warningCaught): bool {
            if ($errno === E_USER_WARNING) {
                $warningCaught = true;

                return true;
            }

            return false;
        });
        try {
            $page = $resource->get('page://self/html/blog-posting', ['id' => 0]);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($warningCaught, 'the donut read warns instead of throwing');
        $this->assertSame(200, $page->code);

        $tree = $this->flushAndValidate($logger);
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the outage is recorded, not swallowed');
        $this->assertStringContainsString('"operation":"read"', $error);
        $this->assertStringContainsString('cache server down', $error);
    }

    public function testABrokenCacheableReadKeepsTravelling(): void
    {
        // `QueryRepositoryInterface` is a binding an application can replace, so a read can fail
        // for a reason the store never raised. Degrading that would answer 200 from a defect
        // nobody sees; only what the store raised is forgiven.
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(QueryRepositoryInterface::class)->to(FakeDefectiveQueryRepository::class);
            }
        });

        $this->assertDefectTravels($module, 'app://self/user?id=1', 'read');
    }

    public function testABrokenDonutReadKeepsTravelling(): void
    {
        // The donut interceptor has its own copy of the decision, which is how one of them rots.
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(DonutRepositoryInterface::class)->toInstance(new FakeDefectiveDonutRepository('read'));
            }
        });

        $this->assertDefectTravels($module, 'page://self/html/blog-posting?id=0', 'read');
    }

    public function testABrokenDonutWriteKeepsTravelling(): void
    {
        // The real write renders the page it stores, so this catch sees a pool outage and a
        // template bug alike. Only the first degrades: a page that cannot render does not exist.
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(DonutRepositoryInterface::class)->toInstance(new FakeDefectiveDonutRepository('write'));
            }
        });

        $this->assertDefectTravels($module, 'page://self/html/blog-posting?id=0', 'write');
    }

    /** @param 'read'|'write' $operation */
    private function assertDefectTravels(AbstractModule $module, string $uri, string $operation): void
    {
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        try {
            $resource->get($uri);
            $this->fail('expected the defect to surface');
        } catch (FakeRepositoryDefect $e) {
            $this->assertSame($operation . ' is broken', $e->getMessage());
        }

        $tree = $this->flushAndValidate($logger);
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'recorded before it travels');
        $this->assertStringContainsString('"operation":"' . $operation . '"', $error);
        $this->assertStringContainsString('FakeRepositoryDefect', $error, 'the defect is named, not a wrapper');
    }
}
