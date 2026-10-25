<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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
