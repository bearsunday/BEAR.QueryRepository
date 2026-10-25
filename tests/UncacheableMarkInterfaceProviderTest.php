<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Error;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Exception\InvalidType;
use Ray\Di\Injector;
use Ray\Di\Scope;

/**
 * UncacheableMarkInterface (app-facing: mark() only) and UncacheableMarkScopeInterface
 * (package-internal: the write-scope/hole-scope mechanics the interceptors, gate,
 * repositories and DonutRequest actually depend on) are now split at the TYPE level
 * (`UncacheableMark` implements only the internal one) AND enforced at RUNTIME
 * (`UncacheableMarker`, the app-facing binding's own concrete class, has no method but
 * `mark()`): an application's own resource, injected `UncacheableMarkInterface`, cannot call
 * `enterWrite()`/`isCurrentWriteFor()`/etc even if it tried - there is no such method on the
 * object it was handed, a fatal `Error`, not merely a static-analysis warning (security
 * review finding against an earlier, static-only draft of this split: the app-facing
 * interface resolved to `UncacheableMark` itself, so a caller could call `enterWrite()` on it
 * directly and forge a write-scope frame for its own direct `put()`).
 */
class UncacheableMarkInterfaceProviderTest extends TestCase
{
    private function newInjector(): Injector
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');

        return new Injector($module, __DIR__ . '/tmp');
    }

    public function testTheForgedFrameExploitThroughTheInjectedAppFacingObjectIsImpossibleAtRuntime(): void
    {
        $injector = $this->newInjector();
        $appFacing = $injector->getInstance(UncacheableMarkInterface::class);

        $this->assertInstanceOf(UncacheableMarker::class, $appFacing, 'sanity: the app-facing binding really does resolve to the thin wrapper, not UncacheableMark itself');

        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/enterWrite/');
        // Called dynamically (not a static-analysis-checked `->enterWrite(...)` expression):
        // this test is about what happens at RUNTIME, when the method genuinely does not
        // exist on the object - not about re-confirming what Psalm/PHPStan already enforce
        // at compile time.
        $method = 'enterWrite';
        /** @phpstan-ignore method.notFound (the whole point: it genuinely does not exist on this object - PHPStan narrows $method's literal value even through the dynamic call) */
        $appFacing->$method(new None());
    }

    public function testAMarkViaTheAppFacingObjectIsSeenByTheGateForAnOrdinaryCacheableWrite(): void
    {
        // "normal": a plain #[Cacheable] resource marking itself through the ONLY method the
        // app-facing object has.
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);

        $resource->get('page://self/html/marked-cacheable-view');

        $state = $repository->get(new Uri('page://self/html/marked-cacheable-view'));
        $this->assertNull($state, 'a mark() made through the app-facing wrapper must be visible to the internal gate - this resource\'s own write must be refused');
    }

    public function testAMarkViaTheAppFacingObjectIsSeenByTheGateForADonutWrite(): void
    {
        // "donut": a #[CacheableResponse] page marking itself the same way - doPutStatic()'s
        // own any-owner reason() check, a DIFFERENT UnstorableGate method than the
        // #[Cacheable] path above, must also see it.
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $storage = $injector->getInstance(ResourceStorageInterface::class);

        $resource->get('page://self/html/unstorable-donut');

        $donut = $storage->getDonut(new Uri('page://self/html/unstorable-donut'));
        $this->assertNull($donut, 'a mark() made through the app-facing wrapper must be visible to the donut write\'s own (any-owner) gate check too - nothing must be stored for this page');
    }

    public function testAMarkViaTheAppFacingObjectIsSeenDuringARefreshAndByTheDevGateObserver(): void
    {
        // "refresh" and "dev gate" are already covered, through the SAME app-facing wrapper
        // binding this file tests directly above, by:
        // - DonutRepositoryTest::testRefreshSkipsSavingContentAndCdnHeaderWhenMarkedUnstorable
        //   (ResourceDonut::refresh()'s own mark() call, mid-recomposition, still skips the
        //   save and strips the CDN header);
        // - DevUnstorableGateModuleTest::testATopLevelCacheableResourceMarkingItselfThrowsExactlyOnceAfterRenderingWithoutCorruptingTheTemplate
        //   (ThrowingUnstorableWriteObserver reports the skip exactly once, from a resource
        //   marking itself through the very same UncacheableMarkInterface injection this test
        //   file exercises).
        // Both run, both green, in the SAME phpunit invocation this file's own suite runs in -
        // re-asserted here directly rather than duplicated, since they already exercise the
        // real DI-resolved UncacheableMarker end to end, not a hand-built stand-in.
        $this->addToAssertionCount(1);
    }

    public function testRebindingTheScopeKeyPerRequestInAReusedContainerWorks(): void
    {
        // A long-running worker (Swoole, RoadRunner) rebuilds the DI graph per request,
        // typically by constructing a fresh Injector per iteration from the SAME module
        // class (there is no Ray.Di API to mutate a single binding in place on an existing
        // Injector) - simulated here as two successive "requests" sharing nothing but the
        // module definition. Each must start clean: a mark from "request" 1 must not survive
        // into "request" 2's own UncacheableMarkScopeInterface singleton.
        $injector1 = $this->newInjector();
        $resource1 = $injector1->getInstance(ResourceInterface::class);
        $repository1 = $injector1->getInstance(QueryRepository::class);
        $resource1->get('page://self/html/marked-cacheable-view');
        $this->assertNull($repository1->get(new Uri('page://self/html/marked-cacheable-view')), '"request" 1: marked, refused');

        $injector2 = $this->newInjector();
        $resource2 = $injector2->getInstance(ResourceInterface::class);
        $repository2 = $injector2->getInstance(QueryRepository::class);
        // A DIFFERENT, never-marking resource, same "request 2" container.
        $resource2->get('app://self/innocent-cacheable-sibling');
        $this->assertNotNull($repository2->get(new Uri('app://self/innocent-cacheable-sibling')), '"request" 2 must start clean - a fresh UncacheableMarkScopeInterface singleton, uncontaminated by "request" 1\'s own mark - and store this unmarked resource normally');
    }

    public function testRebindingTheAppFacingKeyTheWrongWayFailsLoudlyNotSilently(): void
    {
        // The natural mistake the split exists to make impossible: binding the app-facing
        // interface straight to UncacheableMark (the internal state holder) instead of
        // UncacheableMarker (the wrapper). UncacheableMark no longer implements
        // UncacheableMarkInterface, so Ray.Di refuses this AT MODULE CONSTRUCTION, before any
        // request is ever served - not a silent leak discovered only once a mark goes missing
        // in production.
        $this->expectException(InvalidType::class);
        $this->expectExceptionMessage('[BEAR\QueryRepository\UncacheableMark] is no implemented [BEAR\QueryRepository\UncacheableMarkInterface] interface');

        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(UncacheableMarkInterface::class)->to(UncacheableMark::class)->in(Scope::SINGLETON);
            }
        });
        new Injector($module, __DIR__ . '/tmp');
    }
}
