<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
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
use function is_array;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

class DevUnstorableGateModuleTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testOverrideIsTheSupportedWiringAndResolvesToWarningUnstorableWriteObserver(): void
    {
        // The only wiring that actually works: QueryRepositoryModule already binds
        // UnstorableWriteObserverInterface (to the no-op default), and Ray.Di's install()
        // never replaces an existing binding (Container::merge() keeps the existing key), so
        // this module must be installed via override().
        $module = new QueryRepositoryModule();
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);

        $this->assertInstanceOf(WarningUnstorableWriteObserver::class, $observer);
    }

    public function testTheOverriddenObserverIsActuallyWiredIntoQueryRepositoryAndDonutRepositoryNotJustResolvableOnItsOwn(): void
    {
        // Stronger than the test above: proves DI actually threads the SAME overridden
        // (WarningUnstorableWriteObserver) instance into QueryRepository's and
        // DonutRepository's own private $writeObserver property (via Reflection) - not just
        // that the interface resolves to it when asked directly.
        $injector = $this->getInjector();
        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);
        $this->assertInstanceOf(WarningUnstorableWriteObserver::class, $observer);

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

        $this->assertNotInstanceOf(WarningUnstorableWriteObserver::class, $observer, 'install() must not be documented as working - it silently keeps the original binding');
    }

    /**
     * Bare injector: NO log module installed, so SemanticLoggerInterface resolves to the
     * package default NullSemanticLogger.
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

    public function testCorrectDonutPageWithALegitimatelyMarkingHoleRaisesNoWarningAndStoresItsTemplate(): void
    {
        // A correctly designed #[DonutCache] page whose hole legitimately marks itself
        // (reason() non-null for the page response is expected there) must not be reported -
        // the observer suppresses this: isMarkedForCurrentScope() && isNested() at the hole's
        // own (still-open) write check.
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

        [$ro, $warnings] = $this->getAndCaptureWarnings($resource, 'page://self/html/donut-cache-with-unstorable-hole');
        $this->assertSame(200, $ro->code);
        $this->assertSame([], $warnings, 'a hole legitimately refusing its own write must not be reported');
        $this->flushAndValidate($logger);

        $resource->get('page://self/html/donut-cache-with-unstorable-hole');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the template was actually saved - the second GET recomposes from it, not a fresh MISS');
    }

    /**
     * The ETag-shortcut hole-only-mark case: AbstractDonutCacheInterceptor's "already
     * answered" shortcut must reach the same dev-gate outcome as a normal write for a
     * hole-only mark - doPutDonut() never reports a hole refusing its own write
     * (reasonExcludingHoleMarks() stays null, the template is unaffected by a hole's own
     * content), only reason() (any-owner) refuses the live response header, because that
     * response really does carry the hole's marked content.
     */
    public function testADonutPageOnTheShortcutPathWithALegitimatelyMarkingHoleRaisesNoWarningAndStillRefusesItsHeader(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $scope = $injector->getInstance(UncacheableMarkScopeInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);

        $scope->enterHole('app://self/some-hole');
        $mark->mark('csrf-token-issued');
        $scope->leaveHole('app://self/some-hole');

        [$ro, $warnings] = $this->getAndCaptureWarnings($resource, 'page://self/html/donut-cache-shortcut-path-self-etag');

        $this->assertSame(200, $ro->code, 'a hole refusing its own write is expected, not reported');
        $this->assertSame([], $warnings, 'a hole legitimately refusing its own write must not be reported');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL] ?? null, 'the live response must still be refused - the hole-only split exempts only the dev gate, not the header');
    }

    public function testRefuseIfUnstorablesPurgeDegradesAPoolOutageButLetsAGenuineFailurePropagate(): void
    {
        // Mirrors CacheInterceptor::writeFreshResult()'s own write-failure degrade: a pool
        // outage on refuseIfUnstorable()'s purge() call must not turn an already-rendered,
        // header-refused response into a 500 - the response is already correct, only the
        // stale cache entry survives a beat longer. A non-CacheStoreFailure defect on that
        // same purge is not this path's to swallow and keeps travelling.
        $uri = 'page://self/html/donut-cache-shortcut-path-self-etag';
        $module = new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepositoryInterface::class)->to(FakePurgeFailsForOneUri::class)->in(Scope::SINGLETON);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $scope = $injector->getInstance(UncacheableMarkScopeInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $fakeDonutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($fakeDonutRepository instanceof FakePurgeFailsForOneUri);
        $fakeDonutRepository->failingUri = $uri;

        $scope->enterHole('app://self/some-hole');
        $mark->mark('csrf-token-issued');
        $scope->leaveHole('app://self/some-hole');

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });
        try {
            $ro = $resource->get($uri);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $ro->code, 'the pool outage on purge degrades - the already-rendered response is still returned, not a 500');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL] ?? null, 'the header refusal already applied before the purge call is unaffected by its own later failure');
        $this->assertNotEmpty($warnings, 'the pool outage triggers a warning, same as any other degraded write');

        // A genuine (non-CacheStoreFailure) failure on that same purge is not this path's to
        // swallow - it must propagate.
        $fakeDonutRepository->genuine = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('purge genuinely failed for this uri, not a degradable pool outage');
        $resource->get($uri);
    }

    /**
     * The marked-and-error-code case: $hasEtag in handleProceedResult() keeps the
     * error-code branch silent regardless of this page's own mark - an error-code
     * response is never dev-gate-reportable.
     */
    public function testADonutPageThatMarksItselfAndAnswersAValidationErrorRaisesNoWarning(): void
    {
        [$ro, $warnings] = $this->getAndCaptureWarnings($this->getInjector()->getInstance(ResourceInterface::class), 'page://self/html/donut-cache-self-marking-validation-error');

        $this->assertSame(400, $ro->code);
        $this->assertSame([], $warnings, 'an error-code response is never dev-gate-reportable');
    }

    public function testADonutPageThatMarksItselfAndAnswersAValidationErrorLogsNoCodeForTheUnstorableReason(): void
    {
        // put_skipped's own schema contract: code is "the response status code when reason
        // is error-code; null otherwise" (docs/schemas/context/put_skipped.json). The same
        // page as the test above, but with a recording logger: the reason this write was
        // skipped is 'unstorable' (the mark), not 'error-code' (merely incidental here), so
        // code must stay null even though $ro->code is 400.
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

        $ro = $resource->get('page://self/html/donut-cache-self-marking-validation-error');
        $this->assertSame(400, $ro->code);

        $tree = $this->flushAndValidate($logger);
        $json = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($json);
        $this->assertStringContainsString('"reason":"unstorable"', $json);
        $this->assertStringContainsString('"code":null', $json, 'code must stay null for reason "unstorable", even though the response itself is 400 - only reason "error-code" carries a code');
    }

    /**
     * The actual #222 misuse a developer needs to see: no hole to isolate the mark in
     * (isNested() is false), so this is reported - exactly once, naming the offending
     * URI and reason, after the response has already rendered correctly.
     */
    public function testATopLevelCacheableResourceMarkingItselfRaisesExactlyOneWarningNamingTheUriAndReasonAfterRendering(): void
    {
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        [$ro, $warnings] = $this->getAndCaptureWarnings($resource, 'page://self/html/marked-cacheable-view');

        $this->assertSame(200, $ro->code, 'the response still renders correctly - the mark only affects storability');
        assert(is_array($ro->body));
        $this->assertSame('secret', $ro->body['token'] ?? null);
        $this->assertCount(1, $warnings);
        $this->assertSame('put_skipped unstorable: page://self/html/marked-cacheable-view', $warnings[0]);
    }

    /**
     * Unlike a #[DonutCache] template (which a hole's mark never blocks), doPutStatic()
     * stores the WHOLE content, any-owner isMarked() (unchanged, #234) - and by the time
     * that refusal is checked the hole's own enterHole()/leaveHole() scope has already
     * closed, so this is not suppressed.
     */
    public function testACacheableResponsePageWhoseHoleMarksWarns(): void
    {
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        [, $warnings] = $this->getAndCaptureWarnings($resource, 'page://self/html/cacheable-response-with-marking-hole');

        $this->assertNotEmpty($warnings);
    }

    public function testWithNoLogModuleInstalledTheObserverStillWarns(): void
    {
        $injector = $this->getInjector();
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->assertInstanceOf(NullSemanticLogger::class, $logger, 'sanity: no log module is installed in this fixture');

        [, $warnings] = $this->getAndCaptureWarnings($injector->getInstance(ResourceInterface::class), 'app://self/unstorable-value');

        $this->assertNotEmpty($warnings);
    }

    /** @return array{0: ResourceObject, 1: list<string>} */
    private function getAndCaptureWarnings(ResourceInterface $resource, string $uri): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });

        try {
            $ro = $resource->get($uri);
        } finally {
            restore_error_handler();
        }

        return [$ro, $warnings];
    }
}
