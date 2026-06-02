<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\SemanticLogValidator;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function tempnam;

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
        foreach (['get', 'cache_miss', 'depends_on', 'invalidate', 'save_value', 'save_etag'] as $type) {
            $this->assertContains($type, $types);
        }
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
                        'context' => [], // missing "layer"
                    ],
                ],
            ],
        ];
        $file = (string) tempnam(sys_get_temp_dir(), 'slog');
        file_put_contents($file, (string) json_encode($tree, JSON_UNESCAPED_SLASHES));

        $this->expectException(RuntimeException::class);
        ob_start();
        try {
            (new SemanticLogValidator())->validate($file, dirname(__DIR__) . '/docs/schemas/context');
        } finally {
            ob_get_clean();
        }
    }
}
