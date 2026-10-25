<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\Scope;

/**
 * UncacheableMarkInterface (app-facing: mark() only) and UncacheableMarkScopeInterface
 * (package-internal: the write-scope/hole-scope mechanics the interceptors, gate,
 * repositories and DonutRequest actually depend on) must resolve to the SAME request-scoped
 * instance - a mark() an application's own resource makes through the app-facing interface
 * has to be visible to the internal mechanics that decide whether to refuse a write, or the
 * split silently reintroduces #222/#234: a store that never sees the mark stores the marked
 * secret regardless of how carefully the internal scoping itself is written.
 *
 * This is also the fix for the finding the split exists to prevent in the other direction:
 * before the split, application code holding the single, do-everything
 * UncacheableMarkInterface could call enterWrite($myOwnRo) itself, forging a write-scope frame
 * for its own direct put() and making an UNRELATED mark()'s refusal look scoped away from it.
 * Splitting the interface, not just documenting the misuse, is what makes that impossible:
 * UncacheableMarkInterface no longer declares enterWrite() at all.
 */
class UncacheableMarkInterfaceProviderTest extends TestCase
{
    private function newInjector(): Injector
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');

        return new Injector($module, __DIR__ . '/tmp');
    }

    public function testUncacheableMarkInterfaceAndUncacheableMarkScopeInterfaceResolveToTheSameInstance(): void
    {
        $injector = $this->newInjector();

        $appFacing = $injector->getInstance(UncacheableMarkInterface::class);
        $internal = $injector->getInstance(UncacheableMarkScopeInterface::class);

        $this->assertSame($appFacing, $internal, 'both interfaces must be backed by the SAME UncacheableMark instance - a mark() made through one has to be visible to the other');
    }

    public function testAMarkMadeThroughTheAppFacingInterfaceRefusesThisResourcesOwnWriteThroughTheInternalInterface(): void
    {
        // MarkedCacheableView marks itself via its OWN injected UncacheableMarkInterface
        // (app-facing) - exactly how application code is meant to use it. Its own write,
        // gated by UnstorableGate through UncacheableMarkScopeInterface (internal), must see
        // that mark and refuse to store it.
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);

        $resource->get('page://self/html/marked-cacheable-view');

        $state = $repository->get(new Uri('page://self/html/marked-cacheable-view'));
        $this->assertNull($state, 'a mark() made through the app-facing interface must be visible to the internal interface the gate actually consults - this resource\'s own write must be refused');
    }

    public function testASeparateInstanceForEachInterfaceWouldSilentlyLoseTheMark(): void
    {
        // Mutation, by DI wiring rather than by editing source: binding each interface to
        // its OWN separate ->to() target (no shared provider) is exactly what the real
        // QueryRepositoryModule binding must NOT do - if it ever regressed to this, the mark
        // made through the app-facing interface would be invisible to the gate, and the
        // resource above would be stored instead of refused.
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(UncacheableMarkScopeInterface::class)->to(UncacheableMark::class)->in(Scope::SINGLETON);
                $this->bind(UncacheableMarkInterface::class)->to(UncacheableMark::class)->in(Scope::SINGLETON);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');

        $appFacing = $injector->getInstance(UncacheableMarkInterface::class);
        $internal = $injector->getInstance(UncacheableMarkScopeInterface::class);
        $this->assertNotSame($appFacing, $internal, 'sanity: this wiring really does produce two separate instances, the broken shape this test guards against');

        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);

        $resource->get('page://self/html/marked-cacheable-view');

        $state = $repository->get(new Uri('page://self/html/marked-cacheable-view'));
        $this->assertNotNull($state, 'with two separate instances, the mark made through the app-facing interface never reaches the gate\'s own (internal-interface) instance - the marked secret would be stored, exactly the regression the shared-instance wiring prevents');
    }
}
