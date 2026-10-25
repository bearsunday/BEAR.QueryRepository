<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\Scope;

use function assert;
use function dirname;
use function restore_error_handler;
use function set_error_handler;

/**
 * UncacheableMarkScopeInterface::isCurrentWriteFor() - a direct (non-AOP) QueryRepository::put()
 * call has no write-scope frame of its own, so a CacheInterceptor/AbstractDonutCacheInterceptor
 * write-scope frame belonging to a DIFFERENT resource must never be trusted for it (Kimi's
 * compound-leak finding), yet removing write-scope tracking altogether must not make it
 * over-refuse the UNRELATED #[Cacheable] siblings normal #234 scoping still protects
 * (Fable's finding). Both resolve together: a write only trusts the scoped #234 predicate
 * (isMarkedForCurrentScope() || wasMarkedSinceCurrentWrite()) when the innermost open write
 * frame's own owner matches the resource actually being written; otherwise it falls back to
 * the conservative, any-owner isMarked() - pre-#234/1.17.0 behaviour, which can over-refuse
 * but never leaks.
 */
class DirectWriteScopeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private function newInjector(): Injector
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = ModuleFactory::getInstance($namespace);
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(QueryRepository::class)->in(Scope::SINGLETON);
                $this->bind(QueryRepositoryInterface::class)->to(FakeReadFailsForOneUri::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepositoryInterface::class)->to(FakeDonutReadFailsForOneUri::class)->in(Scope::SINGLETON);
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance(new Log\SafeSemanticLogger());
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        return new Injector($module, __DIR__ . '/tmp');
    }

    public function testADirectPutInsideCacheInterceptorsDegradedReadFallbackDoesNotLeakACompoundParentsSecret(): void
    {
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $fakeRepository = $injector->getInstance(QueryRepositoryInterface::class);
        assert($fakeRepository instanceof FakeReadFailsForOneUri);
        $fakeRepository->failingUri = 'app://self/direct-put-leaks-compound-parent-via-degraded-read-fallback';

        // triggerWarning() fires as part of this degrade - expected, suppressed the same way
        // DevUnstorableGateModuleTest does for the identical call site.
        set_error_handler(static fn (): bool => true);
        $resource->get('app://self/direct-put-leaks-compound-parent-via-degraded-read-fallback');
        restore_error_handler();

        $repository = $injector->getInstance(QueryRepository::class);
        $state = $repository->get(new Uri('app://self/direct-put-compound-parent-cache-interceptor'));
        $this->assertNull($state, 'a direct put() nested inside a degraded-read fallback, carrying a secret baked in from an independently donut-composed marking hole, must not be stored - no write-scope frame of its own means it falls back to conservative, any-owner isMarked()');

        // Positive control: the put() genuinely reached the gate and was refused for THIS
        // exact uri - assertNull above cannot tell "refused" from "never attempted" or "the
        // uri drifted" apart on its own.
        $tree = $this->flushAndValidate($logger);
        $putSkipped = self::eventContextsJsonOf($tree, 'put_skipped', 'app://self/direct-put-compound-parent-cache-interceptor');
        $this->assertNotEmpty($putSkipped, 'the direct put() must have actually reached the gate for this exact uri');
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped[0]);
    }

    public function testADirectPutInsideADonutPagesDegradedReadFallbackDoesNotLeakACompoundParentsSecret(): void
    {
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $fakeDonutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($fakeDonutRepository instanceof FakeDonutReadFailsForOneUri);
        $fakeDonutRepository->failingUri = 'page://self/html/donut-direct-put-leaks-compound-parent-via-degraded-read-fallback';

        set_error_handler(static fn (): bool => true);
        $resource->get('page://self/html/donut-direct-put-leaks-compound-parent-via-degraded-read-fallback');
        restore_error_handler();

        $repository = $injector->getInstance(QueryRepository::class);
        $state = $repository->get(new Uri('app://self/direct-put-compound-parent-donut-fallback'));
        $this->assertNull($state, 'the same compound-leak scenario, reached via AbstractDonutCacheInterceptor\'s own degraded-read fallback - which, like CacheInterceptor\'s, opens no write scope of its own - must not be stored either');

        $tree = $this->flushAndValidate($logger);
        $putSkipped = self::eventContextsJsonOf($tree, 'put_skipped', 'app://self/direct-put-compound-parent-donut-fallback');
        $this->assertNotEmpty($putSkipped, 'the direct put() must have actually reached the gate for this exact uri');
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped[0]);
    }

    public function testADirectPutInsideADonutPageAfterAnUnrelatedHoleMarkIsConservativelyRefusedWhileTheAopCachedSiblingIsStillStored(): void
    {
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $resource->get('page://self/html/donut-page-with-marking-hole-cacheable-sibling-and-direct-put');

        $repository = $injector->getInstance(QueryRepository::class);

        $directState = $repository->get(new Uri('app://self/direct-put-after-unrelated-hole-mark'));
        $this->assertNull($directState, 'a direct put(), nested inside this page\'s own rendering after an unrelated hole already marked during it, has no write-scope frame of its own - conservative, any-owner isMarked() refuses it, the same as 1.17.0/pre-#234 behaviour (not a regression)');

        $siblingState = $repository->get(new Uri('app://self/innocent-cacheable-sibling'));
        $this->assertNotNull($siblingState, 'the #[Cacheable] sibling, reached through its OWN CacheInterceptor-opened write scope (isCurrentWriteFor() true for its own uri), stays correctly #234-scoped and must still be stored - the unrelated hole\'s mark must not over-refuse it');

        $tree = $this->flushAndValidate($logger);
        $putSkipped = self::eventContextsJsonOf($tree, 'put_skipped', 'app://self/direct-put-after-unrelated-hole-mark');
        $this->assertNotEmpty($putSkipped, 'the direct put() must have actually reached the gate for this exact uri, not been silently skipped by a drifted uri or an early exception');
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped[0]);
    }

    public function testAFramelessDirectPutAfterAnUnrelatedMarkIsRefusedWhileTheSameResourceCachedThroughItsOwnCacheInterceptorIsStored(): void
    {
        // The SAME uri, two different writes: a frameless direct put() (no write-scope frame
        // at all - called from here, not nested inside any interceptor) for it is refused by
        // the unrelated mark below (any-owner isMarked(), isCurrentWriteFor() false with no
        // open frame); the real #[Cacheable] resource at that SAME uri, read normally right
        // after, opens its OWN CacheInterceptor frame (isCurrentWriteFor() true for its own
        // instance) and is stored - the unrelated mark shares neither
        // isMarkedForCurrentScope() (made and closed inside a different hole) nor
        // wasMarkedSinceCurrentWrite() (already ticked before either write's own enterWrite()
        // snapshotted it).
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);
        $scope = $injector->getInstance(UncacheableMarkScopeInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);

        $scope->enterHole('page://self/html/unrelated-hole');
        $mark->mark('csrf-token-issued');
        $scope->leaveHole('page://self/html/unrelated-hole');

        $uri = new Uri('app://self/innocent-cacheable-sibling');
        $direct = new None();
        $direct->uri = $uri;
        $direct->code = 200;
        $direct->body = ['value' => 'direct'];
        $repository->put($direct);
        $this->assertNull($repository->get($uri), 'the frameless direct put() has no write-scope frame of its own - any-owner isMarked() (true) refuses it');

        $resource->get('app://self/innocent-cacheable-sibling');
        $this->assertNotNull($repository->get($uri), 'the SAME uri, read normally through its own CacheInterceptor-opened frame, is correctly #234-scoped and must be stored - the unrelated mark never shared its scope or write window');
    }

    public function testAnUnrelatedPreExistingMarkDoesNotOverRefuseAResourceWhoseOnGetReturnsAFreshInstance(): void
    {
        // CacheInterceptor::setWriteOwner() re-bind: enterWrite() opens the frame with the
        // PRE-proceed instance (before onGet() runs), but CacheableReturnsAFreshInstanceFromOnGet
        // returns a DIFFERENT (freshly built) instance from onGet() - the one actually passed
        // to put(). Without the re-bind, isCurrentWriteFor() would never match it, falling
        // back to any-owner isMarked() and wrongly refusing this write past the unrelated,
        // already-closed hole mark below - which #234's own scoping (isMarkedForCurrentScope():
        // different, closed scope; wasMarkedSinceCurrentWrite(): already ticked before this
        // write's own enterWrite()) would otherwise correctly ignore.
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);
        $scope = $injector->getInstance(UncacheableMarkScopeInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);

        $scope->enterHole('page://self/html/unrelated-hole');
        $mark->mark('csrf-token-issued');
        $scope->leaveHole('page://self/html/unrelated-hole');

        $resource->get('app://self/cacheable-returns-a-fresh-instance-from-on-get');

        $state = $repository->get(new Uri('app://self/cacheable-returns-a-fresh-instance-from-on-get'));
        $this->assertNotNull($state, 'the write-scope frame must have been re-bound to the fresh instance proceed() returned, or this write would be wrongly refused by the unrelated, already-closed hole mark above');
    }

    public function testADirectPutInsideOwnOnGetIsRefusedWhenThisResourcesOwnOnGetMarksItselfFirst(): void
    {
        // CodeRabbit review (thread on f728c8d): a direct, manual put($this) call made from
        // WITHIN this resource's own onGet() is still reached while CacheInterceptor's own
        // write-scope frame is open (tagged with the pre-proceed $ro - the SAME instance as
        // $this here, since onGet() returns $this), so isCurrentWriteFor($this) matches by
        // identity and the scoped predicate applies. A mark made by onGet() itself, inside
        // that SAME open write window, before the direct put - wasMarkedSinceCurrentWrite()
        // must catch it, #234's own semantics for a descendant mark during an open write.
        //
        // The final repository->get() state alone cannot prove the INNER (manual) put's own
        // decision: CacheInterceptor's OUTER write runs the SAME $ro right after, in the SAME
        // scope, against the SAME mark state - it necessarily agrees with the inner write
        // either way, so a bug that made ONLY the inner write misbehave could still pass a
        // final-state-only check. Each write logs its own put_skipped event, so asserting the
        // per-URI COUNT (inner + outer, 2) proves BOTH decisions independently, not just the
        // observable outcome.
        // onGet() takes a query param ($markSelf), so the written $ro->uri carries it
        // (?markSelf=1) - both the repository->get() lookup key and the put_skipped filter
        // below have to match the SAME, actual URI the write happened under, not the bare
        // path (a bare-URI lookup would be a different, always-empty cache entry and pass
        // vacuously regardless of whether the write was refused).
        $uri = 'app://self/direct-put-inside-own-on-get?markSelf=1';
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $resource->get('app://self/direct-put-inside-own-on-get', ['markSelf' => true]);

        $state = $repository->get(new Uri($uri));
        $this->assertNull($state, 'onGet() marked its own write window before the direct put() - wasMarkedSinceCurrentWrite() must refuse it, the same as any other mark during an open write');

        $tree = $this->flushAndValidate($logger);
        $this->assertCount(2, self::eventContextsJsonOf($tree, 'put_skipped', $uri), 'both the inner (manual) put() and the outer (AOP) write must have independently refused this URI');
    }

    public function testADirectPutInsideOwnOnGetIsRefusedByAPreTreeMarkSharingItsTopLevelScope(): void
    {
        // A mark made BEFORE this GET even started (before CacheInterceptor's own
        // enterWrite() snapshotted the tick) - wasMarkedSinceCurrentWrite() alone would miss
        // it, but isMarkedForCurrentScope() catches it: the pre-tree mark and this resource's
        // own onGet() share the same (TOP_LEVEL) scope, exactly like
        // UnstorableGateTest::testReasonForOwnWriteStillRefusesAPreExistingMarkInTheSameScope.
        // Per-URI put_skipped count, not just final state - see the sibling test above.
        $uri = 'app://self/direct-put-inside-own-on-get';
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $mark->mark('pre-tree-mark');
        $resource->get($uri);

        $state = $repository->get(new Uri($uri));
        $this->assertNull($state, 'a pre-tree mark sharing this resource\'s own (TOP_LEVEL) scope must still refuse its direct put(), via isMarkedForCurrentScope()');

        $tree = $this->flushAndValidate($logger);
        $this->assertCount(2, self::eventContextsJsonOf($tree, 'put_skipped', $uri), 'both the inner (manual) put() and the outer (AOP) write must have independently refused this URI');
    }

    public function testADirectPutInsideOwnOnGetIsStoredWhenOnlyAnUnrelatedSiblingMarkedAndClosedBeforehand(): void
    {
        // The literal #234 semantics for this path too: an unrelated hole that marked and
        // closed BEFORE this resource's own write-scope frame even opened must not refuse
        // it - neither isMarkedForCurrentScope() (different, closed scope) nor
        // wasMarkedSinceCurrentWrite() (already ticked before this write's own enterWrite())
        // catches it; no secret is involved, this is the conservative-but-not-over-refusing
        // case CodeRabbit's own review asked be checked. Zero put_skipped for this URI proves
        // NEITHER write was refused, not just that something ended up stored.
        $uri = 'app://self/direct-put-inside-own-on-get';
        $injector = $this->newInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepository::class);
        $scope = $injector->getInstance(UncacheableMarkScopeInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $scope->enterHole('page://self/html/unrelated-hole');
        $mark->mark('unrelated-sibling-mark');
        $scope->leaveHole('page://self/html/unrelated-hole');

        $resource->get($uri);

        $state = $repository->get(new Uri($uri));
        $this->assertNotNull($state, 'an unrelated sibling marked and closed BEFORE this resource\'s own write-scope frame opened must not refuse its direct put() - no secret, nothing over-refused');

        $tree = $this->flushAndValidate($logger);
        $this->assertCount(0, self::eventContextsJsonOf($tree, 'put_skipped', $uri), 'neither the inner (manual) put() nor the outer (AOP) write may have been refused - the mark that fired is unrelated and already closed');
    }
}
