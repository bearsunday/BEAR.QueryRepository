<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\Scope;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function assert;
use function dirname;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

class DevUnstorableGateModuleTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testOverrideIsTheSupportedWiringAndResolvesToThrowingUnstorableWriteObserver(): void
    {
        // The only wiring that actually works: QueryRepositoryModule already binds
        // UnstorableWriteObserverInterface (to the no-op default), and Ray.Di's install()
        // never replaces an existing binding (Container::merge() keeps the existing key), so
        // this module must be installed via override().
        $module = new QueryRepositoryModule();
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);

        $this->assertInstanceOf(ThrowingUnstorableWriteObserver::class, $observer);
    }

    public function testTheObserverIsSingletonSoPendingStateIsSharedAcrossInjectionPoints(): void
    {
        // "pending refusals must be shared across injection points" (DevUnstorableGateModule's
        // own docblock): the resource dispatcher's CacheInterceptor and QueryRepository must
        // resolve the SAME observer instance, or a skip recorded by one is never seen by the
        // other's raiseIfPending().
        $module = new QueryRepositoryModule();
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $this->assertSame(
            $injector->getInstance(UnstorableWriteObserverInterface::class),
            $injector->getInstance(UnstorableWriteObserverInterface::class),
        );
    }

    public function testTheOverriddenObserverIsActuallyWiredIntoQueryRepositoryAndDonutRepositoryNotJustResolvableOnItsOwn(): void
    {
        // Stronger than the two tests above: proves DI actually threads the SAME overridden
        // (ThrowingUnstorableWriteObserver) instance into QueryRepository's and
        // DonutRepository's own private $writeObserver property (via Reflection) - not just
        // that the interface resolves to it when asked directly.
        $injector = $this->getInjector();
        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $this->assertInstanceOf(ThrowingUnstorableWriteObserver::class, $observer);

        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $queryRepositoryProperty = (new ReflectionClass(QueryRepository::class))->getProperty('writeObserver');
        $queryRepositoryProperty->setAccessible(true);
        $this->assertSame($observer, $queryRepositoryProperty->getValue($queryRepository));

        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $donutRepositoryProperty = (new ReflectionClass(DonutRepository::class))->getProperty('writeObserver');
        $donutRepositoryProperty->setAccessible(true);
        $this->assertSame($observer, $donutRepositoryProperty->getValue($donutRepository));
    }

    public function testPlainInstallDoesNotSwapTheExistingBinding(): void
    {
        // The negative case this module's docblock warns against: install() silently
        // keeps QueryRepositoryModule's own (no-op) binding.
        $module = new QueryRepositoryModule();
        $module->install(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);

        $this->assertNotInstanceOf(ThrowingUnstorableWriteObserver::class, $observer, 'install() must not be documented as working - it silently keeps the original binding');
    }

    public function testSkippedIsStickyOnlyTheFirstSkipOfARequestIsReported(): void
    {
        // A request can skip more than one write (e.g. two unrelated resources both
        // legitimately refused); only the FIRST is reported - the exception names the first
        // offender, not every one.
        $observer = new ThrowingUnstorableWriteObserver(new UncacheableMark());
        $first = new None();
        $first->uri = new Uri('app://self/first');
        $second = new None();
        $second->uri = new Uri('app://self/second');

        $observer->skipped($first, 'unstorable');
        $observer->skipped($second, 'unstorable');

        $this->expectException(UnstorableResponseDetected::class);
        $this->expectExceptionMessage('app://self/first is unstorable for this request (unstorable)');
        $observer->raiseIfPending();
    }

    public function testRaiseIfPendingClearsStateSoALaterRequestOnTheSameSingletonStartsClean(): void
    {
        $observer = new ThrowingUnstorableWriteObserver(new UncacheableMark());
        $ro = new None();
        $ro->uri = new Uri('app://self/first');

        $observer->skipped($ro, 'unstorable');
        try {
            $observer->raiseIfPending();
            $this->fail('the first raiseIfPending() must throw');
        } catch (UnstorableResponseDetected) {
        }

        // No new skip() in this "later request" - raiseIfPending() must stay silent.
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    /**
     * Bare injector: NO log module installed, so SemanticLoggerInterface resolves to the
     * package default NullSemanticLogger - proving (required test) that the observer's
     * outermost-interceptor detection lives in UncacheableMark, not the logger, which would
     * never fire here.
     */
    private function getInjector(): Injector
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());

        return new Injector($module, __DIR__ . '/tmp');
    }

    public function testCorrectDonutPageWithALegitimatelyMarkingHoleDoesNotThrowAndStoresItsTemplate(): void
    {
        // The bug this replaces: the old ThrowingUnstorableGate threw on a correctly designed
        // #[DonutCache] page whose hole legitimately marks itself (reason() non-null for the
        // page response is expected there) - indistinguishable from an actual regression. The
        // observer suppresses this: isMarkedForCurrentScope() && isNested() at the hole's own
        // (still-open) write check.
        //
        // A recording logger (not the bare injector's NullSemanticLogger) so "stores the
        // template" is proved by a refresh_donut event, not just identical rendered content -
        // this page's content is deterministic either way (article '1' + token 'secret'), so
        // comparing the two views alone would pass even on a cold MISS each time.
        $module = new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance(new Log\SafeSemanticLogger());
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $ro = $resource->get('page://self/html/donut-cache-with-unstorable-hole');
        $this->assertSame(200, $ro->code);
        $this->flushAndValidate($logger);

        $resource->get('page://self/html/donut-cache-with-unstorable-hole');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the template was actually saved - the second GET recomposes from it, not a fresh MISS');
    }

    public function testATopLevelCacheableResourceMarkingItselfThrowsExactlyOnceAfterRenderingWithoutCorruptingTheTemplate(): void
    {
        // The actual #222 misuse a developer needs to see: no hole to isolate the mark in
        // (isNested() is false), so this is reported.
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        [$caught, $warnings] = $this->getAndCaptureWarnings($resource, 'page://self/html/marked-cacheable-view');

        $this->assertInstanceOf(UnstorableResponseDetected::class, $caught);
        // The old bug: ResourceObject::__toString() degrades an uncaught exception thrown
        // mid-render to E_USER_WARNING + an empty string, silently corrupting the template.
        // Throwing only from the interceptor's own outermost finally (never inside any
        // __toString() call) means this never happens.
        $this->assertSame([], $warnings, 'the exception must surface cleanly, never degrade to a warning mid-render');
    }

    public function testACacheableResponsePageWhoseHoleMarksThrows(): void
    {
        // Unlike a #[DonutCache] template (which a hole's mark never blocks), doPutStatic()
        // stores the WHOLE content, any-owner isMarked() (unchanged, #234) - and by the time
        // that refusal is checked the hole's own enterHole()/leaveHole() scope has already
        // closed, so this is NOT suppressed.
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        [$caught] = $this->getAndCaptureWarnings($resource, 'page://self/html/cacheable-response-with-marking-hole');

        $this->assertInstanceOf(UnstorableResponseDetected::class, $caught);
    }

    public function testAGenuineResourceExceptionSurfacesEvenWithAPendingDevGateSkipAndIsNotLeakedToTheNextRequest(): void
    {
        // A sibling (UnstorableValue) marks itself and has its own write skipped first -
        // reported pending by the observer, not suppressed (a plain get(), not a hole, so
        // isNested() is false) - and THEN, still inside the SAME top-level CacheInterceptor
        // scope, something entirely unrelated fails for real (CacheInterceptor's own NEW
        // try/catch around $invocation->proceed()). The pending skip must never replace it.
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);

        try {
            $resource->get('app://self/marks-sibling-then-genuinely-fails');
            $this->fail('expected the genuine RuntimeException to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('a genuine failure unrelated to the pending skip', $e->getMessage());
        }

        // No leak: the pending skip from the sibling was cleared, not raised, when the real
        // exception left CacheInterceptor's own finally - a later request sharing this same
        // (SINGLETON) observer must start clean, not surface a stale UnstorableResponseDetected.
        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    public function testAGenuineResourceExceptionFromTheDegradedReadFallbackSurfacesEvenWithAPendingDevGateSkipAndIsNotLeakedToTheNextRequest(): void
    {
        // A DIFFERENT call site than the write-path proceed() the test above covers: the
        // store's own read degraded to CacheStoreFailure, falling through to
        // CacheInterceptor's warning+proceed() fallback. That fallback must be wrapped the
        // same way, or a genuine exception travelling through THIS path gets replaced too.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(QueryRepository::class)->in(Scope::SINGLETON);
                $this->bind(QueryRepositoryInterface::class)->to(FakeReadFailsForOneUri::class)->in(Scope::SINGLETON);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $fakeRepository = $injector->getInstance(QueryRepositoryInterface::class);
        assert($fakeRepository instanceof FakeReadFailsForOneUri);
        $fakeRepository->failingUri = 'app://self/marks-sibling-then-genuinely-fails';

        // The store-read degrading to a warning (triggerWarning()) is expected here - this
        // test is about what happens AFTER that degrade, not the degrade itself.
        set_error_handler(static fn (): bool => true);
        try {
            $resource->get('app://self/marks-sibling-then-genuinely-fails');
            $this->fail('expected the genuine RuntimeException to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('a genuine failure unrelated to the pending skip', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    public function testAGenuineDonutResourceExceptionFromTheDegradedReadFallbackSurfacesEvenWithAPendingDevGateSkipAndIsNotLeakedToTheNextRequest(): void
    {
        // The donut-interceptor twin of the test above: AbstractDonutCacheInterceptor's own
        // degraded-read fallback (DonutRepositoryInterface::get() throwing CacheStoreFailure).
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepositoryInterface::class)->to(FakeDonutReadFailsForOneUri::class)->in(Scope::SINGLETON);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $fakeRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($fakeRepository instanceof FakeDonutReadFailsForOneUri);
        $fakeRepository->failingUri = 'page://self/html/donut-marks-sibling-then-genuinely-fails';

        set_error_handler(static fn (): bool => true);
        try {
            $resource->get('page://self/html/donut-marks-sibling-then-genuinely-fails');
            $this->fail('expected the genuine RuntimeException to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('a genuine failure unrelated to the pending skip', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    public function testAGenuineDonutStoreFailureFromTheDegradedReadFallbackThatNowStoresSurfacesEvenWithAPendingDevGateSkipAndIsNotLeakedToTheNextRequest(): void
    {
        // #237 sends the donut degraded-read fallback through handleProceedResult(), i.e. a
        // real store attempt (AbstractDonutCacheInterceptor opens no write scope of its own -
        // see UncacheableMarkInterface's own docblock on isCurrentWriteFor() for why identity,
        // not a donut-side scope, is what keeps a nested direct put() safe now). Unlike the
        // test above (the resource's own onGet() throws, before any store is attempted),
        // here onGet() returns normally and the STORE itself - a render failure inside
        // putRecorded()/doPutDonut()/ResourceDonut::create() - fails genuinely, while a
        // pending skip (an unrelated sibling, marked earlier in THIS same top-level request -
        // DonutMarksSiblingThenRendersNormally's own onGet()) is already recorded. The real
        // exception must still surface, not the pending skip.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance(new Log\SafeSemanticLogger());
                $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepositoryInterface::class)->to(FakeDonutReadFailsForOneUri::class)->in(Scope::SINGLETON);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());
        // Applied LAST (after TwigModule, which also binds RenderInterface to TwigRenderer -
        // Ray.Di's override() keeps the LATEST registration, so order matters here): pools
        // are healthy; rendering the template this write stores is what fails.
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(RenderInterface::class)->to(FakeThrowingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $fakeDonutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($fakeDonutRepository instanceof FakeDonutReadFailsForOneUri);

        // The target: its own donutRepository->get() degrades, forcing the NEW fallback
        // path. Its own onGet() marks a sibling first (pending, not suppressed), then
        // returns normally - the render that follows (the store itself) throws genuinely.
        $fakeDonutRepository->failingUri = 'page://self/html/donut-marks-sibling-then-renders-normally';
        set_error_handler(static fn (): bool => true);
        try {
            $resource->get('page://self/html/donut-marks-sibling-then-renders-normally');
            $this->fail('expected the genuine render failure to surface');
        } catch (FakeTemplateNotFound $e) {
            $this->assertSame('template not found: user.html.twig', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        // Sanity: the fake's read actually degraded (not a silently-vacuous normal MISS
        // reaching the same render failure via a different path) - without this, a URI
        // mismatch would let the test pass without ever reaching the fallback under test.
        $tree = $this->flushAndValidate($logger);
        $readError = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($readError, 'sanity: the degraded read must have actually happened');
        $this->assertStringContainsString('"operation":"read"', $readError);

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    public function testAGenuineDonutResourceExceptionSurfacesEvenWithAPendingDevGateSkipAndIsNotLeakedToTheNextRequest(): void
    {
        // The donut-interceptor twin of the test above: AbstractDonutCacheInterceptor's own
        // NEW try/catch around $invocation->proceed().
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);

        try {
            $resource->get('page://self/html/donut-marks-sibling-then-genuinely-fails');
            $this->fail('expected the genuine RuntimeException to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('a genuine failure unrelated to the pending skip', $e->getMessage());
        }

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    public function testAGenuineDonutWriteFailureInPutRecordedSurfacesEvenWithAPendingDevGateSkipAndIsNotLeakedToTheNextRequest(): void
    {
        // Unlike the two tests above (the resource's own onGet() throws, before putRecorded()
        // is ever reached), here onGet() returns normally and the store itself - putRecorded()
        // - fails for a genuine (non-CacheStoreFailure) reason: FakeDefectiveDonutRepository
        // throws from putStatic(). This is putRecorded()'s OWN by-reference $threw, not the
        // interceptor's proceed() wrap.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(DonutRepositoryInterface::class)->toInstance(new FakeDefectiveDonutRepository('write'));
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        try {
            $resource->get('page://self/html/cacheable-response-with-pending-skip-sibling');
            $this->fail('expected the genuine FakeRepositoryDefect to surface');
        } catch (FakeRepositoryDefect $e) {
            $this->assertSame('write is broken', $e->getMessage());
        }

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $observer->raiseIfPending();
        $this->addToAssertionCount(1);
    }

    public function testWithNoLogModuleInstalledTheObserverStillThrows(): void
    {
        $injector = $this->getInjector();
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->assertInstanceOf(NullSemanticLogger::class, $logger, 'sanity: no log module is installed in this fixture');

        [$caught] = $this->getAndCaptureWarnings($injector->getInstance(ResourceInterface::class), 'app://self/unstorable-value');

        $this->assertInstanceOf(UnstorableResponseDetected::class, $caught);
    }

    public function testTheExceptionSurfacesUncaughtOutOfTheTopLevelResourceGetTheHttpBoundary(): void
    {
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        $this->expectException(UnstorableResponseDetected::class);
        $resource->get('page://self/html/unstorable-donut');
    }

    /** @return array{0: UnstorableResponseDetected|null, 1: list<string>} */
    private function getAndCaptureWarnings(ResourceInterface $resource, string $uri): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });

        $caught = null;
        try {
            $resource->get($uri);
        } catch (UnstorableResponseDetected $e) {
            $caught = $e;
        } finally {
            restore_error_handler();
        }

        return [$caught, $warnings];
    }
}
