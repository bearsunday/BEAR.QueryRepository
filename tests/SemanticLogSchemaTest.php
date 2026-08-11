<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\SemanticLogValidator;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_UNESCAPED_SLASHES;

/**
 * Drift-detection tests for the SemanticLogger output
 *
 * Exercises real cache scenarios, asserts the emitted tree validates against the
 * per-context schemas in docs/schemas/context, then proves the validator actually
 * rejects a schema-violating context.
 */
class SemanticLogSchemaTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);

        parent::setUp();
    }

    public function testDependencyChainValidatesAndNestsAsEmbedTree(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $tree = $this->flushAndValidate($this->logger);

        // The embed structure is the log structure: one -> two -> three nested opens.
        $this->assertGreaterThanOrEqual(3, self::maxOpenDepth($tree));

        // Lifecycle and dependency facts are present and schema-valid.
        $types = self::collectTypes($tree);
        foreach (['get', 'cache_miss', 'pre_write_cleanup', 'depends_on', 'invalidate', 'save_value', 'save_etag'] as $type) {
            $this->assertContains($type, $types);
        }
    }

    public function testCleanupInvalidateIsMarkerPrecededAtSource(): void
    {
        // A miss-then-store GET chain: every invalidate here is the write path clearing
        // the entry it is about to rewrite, and the writer records that purpose itself —
        // each invalidate is immediately preceded by its pre_write_cleanup marker.
        $this->resource->get('page://self/dep/level-one');
        $tree = $this->flushAndValidate($this->logger);

        $invalidates = 0;
        foreach (self::scopeEventTypeSequences($tree) as $sequence) {
            foreach ($sequence as $i => $type) {
                if ($type !== 'invalidate') {
                    continue;
                }

                $invalidates++;
                $this->assertGreaterThan(0, $i, 'a cleanup invalidate cannot open the event stream');
                $this->assertSame('pre_write_cleanup', $sequence[$i - 1], 'the writer records its cleanup at the source');
            }
        }

        $this->assertGreaterThanOrEqual(3, $invalidates, 'each level of the chain cleans up before its store');
    }

    public function testPurgeDrivenInvalidateCarriesNoCleanupMarker(): void
    {
        // User::onPut carries #[Purge] and #[Refresh]: the purge-driven invalidate is a
        // real invalidation (preceded by `purge`, not by the marker), while the refresh
        // re-put still records its own marker-preceded cleanup — both shapes in one tree.
        $this->resource->put('app://self/user', ['id' => 1, 'name' => 'bear', 'age' => 10]);
        $tree = $this->flushAndValidate($this->logger);

        $real = 0;
        $cleanup = 0;
        foreach (self::scopeEventTypeSequences($tree) as $sequence) {
            foreach ($sequence as $i => $type) {
                if ($type !== 'invalidate') {
                    continue;
                }

                $i > 0 && $sequence[$i - 1] === 'pre_write_cleanup' ? $cleanup++ : $real++;
            }
        }

        $this->assertGreaterThanOrEqual(1, $real, 'the #[Purge]-driven invalidate has no cleanup marker');
        $this->assertGreaterThanOrEqual(1, $cleanup, 'the #[Refresh] re-put records its cleanup marker');
    }

    public function testCommandScopeRecordsCausality(): void
    {
        // User::onPut carries #[Purge] and #[Refresh]; the command scope records them.
        $this->resource->put('app://self/user', ['id' => 1, 'name' => 'bear', 'age' => 10]);
        $tree = $this->flushAndValidate($this->logger);

        $commandContext = self::contextJsonOf($tree, 'command');
        $this->assertNotNull($commandContext, 'a command scope is opened');
        $this->assertStringContainsString('"method":"onPut"', $commandContext);
        $this->assertStringContainsString('Refresh', $commandContext);
        $this->assertStringContainsString('Purge', $commandContext);
        $this->assertStringContainsString('"source":"CommandInterceptor"', $commandContext);

        $types = self::collectTypes($tree);
        $this->assertContains('purge', $types, 'the #[Purge]/#[Refresh] invalidations nest under the command scope');
        $this->assertContains('command_result', $types, 'the scope close records the command outcome');
    }

    public function testFailedCommandRecordsScopeWithNoInvalidationEvents(): void
    {
        // User::onPatch with an empty name returns 400. A failed write must still open a
        // command scope — closed with the 4xx result and no invalidation events — so the
        // log shows the purge/refresh was correctly skipped rather than silently absent.
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => '']);
        $tree = $this->flushAndValidate($this->logger);

        $commandContext = self::contextJsonOf($tree, 'command');
        $this->assertNotNull($commandContext, 'a failed write still opens a command scope');
        $this->assertStringContainsString('"method":"onPatch"', $commandContext);
        $close = self::closeContextJsonOf($tree, 'command_result');
        $this->assertNotNull($close);
        $this->assertStringContainsString('"code":400', $close);

        $types = self::collectTypes($tree);
        $this->assertNotContains('purge', $types, 'no purge on a failed write');
        $this->assertNotContains('invalidate', $types, 'no invalidation on a failed write');
    }

    public function testNon200GetLogsPutSkippedWithActualCode(): void
    {
        // Code::onGet returns 203. A non-200 GET is purged, not stored; the log must
        // record the actual code — without it a 203 and a 404 are indistinguishable.
        $this->resource->get('app://self/code');
        $tree = $this->flushAndValidate($this->logger);

        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped, 'the skipped put is recorded');
        $this->assertStringContainsString('"reason":"error-code"', $putSkipped);
        $this->assertStringContainsString('"code":203', $putSkipped, 'the actual response code is recorded');

        $types = self::collectTypes($tree);
        $this->assertContains('purge', $types, 'a non-200 response is purged instead of stored');
    }

    public function testSecondGetClosesWithResourceLayerCacheHit(): void
    {
        // First GET is a cold miss and populates the cache; drain its session.
        $this->resource->get('app://self/user', ['id' => 1]);
        $this->logger->flush();

        // Second GET must close the get scope with a resource-layer cache_hit —
        // the resource layer had no cache_hit pin (only the donut layers did).
        $this->resource->get('app://self/user', ['id' => 1]);
        $tree = $this->flushAndValidate($this->logger);

        $close = self::closeContextJsonOf($tree, 'cache_hit');
        $this->assertNotNull($close, 'the second GET is served from cache');
        $this->assertStringContainsString('"layer":"resource"', $close);
    }

    public function testTopLevelPutIsRootedInManualStoreScope(): void
    {
        // A direct put() has no enclosing AOP scope, so it must root its save events under a
        // manual_store scope; otherwise SemanticLogger drops the event-only session at flush.
        $ro = new None();
        $ro->uri = new Uri('page://self/none');
        $this->repository->put($ro);
        $tree = $this->flushAndValidate($this->logger);

        $types = self::collectTypes($tree);
        $this->assertContains('manual_store', $types, 'a manual_store scope roots the direct put');
        $this->assertContains('manual_store_result', $types, 'the scope close records the store outcome');
        $this->assertContains('save_value', $types, 'the save event nests under the manual_store scope');
    }

    public function testTopLevelInvalidateIsRootedInManualInvalidateScope(): void
    {
        // A direct invalidateTags() has no enclosing AOP scope, so it must root its outcome
        // under a manual_invalidate scope to stay visible in the flushed log.
        $this->storage->invalidateTags(['_test_tag_']);
        $tree = $this->flushAndValidate($this->logger);

        $types = self::collectTypes($tree);
        $this->assertContains('manual_invalidate', $types, 'a manual_invalidate scope roots the direct invalidation');
        $this->assertContains('invalidate', $types, 'the scope close records the invalidation outcome');
    }

    public function testTopLevelPurgeIsRootedInManualPurgeScope(): void
    {
        // A direct purge() has no enclosing AOP scope, so it must root its invalidation
        // under a manual_purge scope to stay visible in the flushed log.
        $this->repository->purge(new Uri('page://self/user'));
        $tree = $this->flushAndValidate($this->logger);

        $types = self::collectTypes($tree);
        $this->assertContains('manual_purge', $types, 'a manual_purge scope roots the direct purge');
        $this->assertContains('invalidate', $types, 'the invalidation nests under the manual_purge scope');
        $this->assertContains('manual_purge_result', $types, 'the scope close records the purge outcome');
    }

    public function testTopLevelPurgeLogsFailClosedOutcomeWhenPurgerFails(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(PurgerInterface::class)->toInstance(new FakeThrowingPurger());
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $repository = $injector->getInstance(QueryRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // The CDN purge is fail-closed: the exception propagates through purge()'s try/finally.
        try {
            $repository->purge(new Uri('page://self/user'));
            $this->fail('Expected the purger failure to propagate (fail-closed)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('purge failed', $e->getMessage());
        }

        // The flushed tree is still well-formed: the manual_purge scope is closed with the
        // failed outcome, and the nested invalidate event records cdn=failed.
        $tree = $this->flushAndValidate($logger);
        $invalidate = self::eventContextJsonOf($tree, 'invalidate');
        $this->assertNotNull($invalidate);
        $this->assertStringContainsString('"cdn":"failed"', $invalidate);
        $close = self::closeContextJsonOf($tree, 'manual_purge_result');
        $this->assertNotNull($close);
        $this->assertStringContainsString('"result":"failed"', $close);
    }

    public function testValidatorRejectsContextViolatingItsSchema(): void
    {
        // cache_hit context without the required "layer" must be rejected.
        $tree = [
            '$schema' => 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json',
            'open' => [
                [
                    'id' => 'get_1',
                    'type' => 'get',
                    'schemaUrl' => 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/get.json',
                    'context' => ['uri' => 'page://self/x'],
                    'close' => [
                        'id' => 'cache_hit_1',
                        'type' => 'cache_hit',
                        'schemaUrl' => 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cache_hit.json',
                        // an empty object (not []) so the rejection comes from the JSON-schema
                        // layer, not the validator's structural guard
                        'context' => (object) [], // missing "layer"
                    ],
                ],
            ],
        ];
        $file = (string) tempnam(sys_get_temp_dir(), 'slog');
        file_put_contents($file, (string) json_encode($tree, JSON_UNESCAPED_SLASHES));

        $exception = null;
        ob_start();
        try {
            (new SemanticLogValidator())->validate($file, dirname(__DIR__) . '/docs/schemas/context');
        } catch (RuntimeException $e) {
            $exception = $e;
        } finally {
            $output = (string) ob_get_clean();
            unlink($file);
        }

        $this->assertInstanceOf(RuntimeException::class, $exception, 'a schema-violating context must be rejected');
        // The schema layer names the failing type; the structural guard never does.
        $this->assertStringContainsString('(cache_hit)', $output, 'the rejection must come from schema validation');
    }
}
