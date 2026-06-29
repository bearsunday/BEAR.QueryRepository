<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
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
        foreach (['get', 'cache_miss', 'depends_on', 'save_state', 'save_etag'] as $type) {
            $this->assertContains($type, $types);
        }

        // A read-through populate (all cache-miss) invalidates nothing: it stores fresh
        // state and clears only each resource's own ETag. Dependents and the CDN are the
        // concern of an explicit purge / command, not a read.
        $this->assertNotContains('invalidate', $types);
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

    public function testDirectCacheWriteNestsUnderCallerScopeAndDoesNotSelfRoot(): void
    {
        // The cache layer is a nested logger: a direct put()/invalidateTags() never opens its
        // own root scope. Their events nest under whatever scope the caller has open — in
        // production, the resource invocation from BEAR.EventSourcing. Here a GET scope stands
        // in for that enclosing scope.
        $ro = new None();
        $ro->uri = new Uri('page://self/none');

        $openId = $this->logger->open(new GetContext('page://self/none'));
        $this->repository->put($ro);
        $this->storage->invalidateTags(['_test_tag_']);
        $this->logger->close(new CacheMissContext('resource'), $openId);

        $tree = $this->flushAndValidate($this->logger);
        $types = self::collectTypes($tree);
        $this->assertContains('save_state', $types, 'the direct put save event nests under the caller scope');
        $this->assertContains('invalidate', $types, 'the direct invalidation nests under the caller scope');
        $this->assertNotContains('manual_store', $types, 'the cache layer does not self-root');
        $this->assertNotContains('manual_invalidate', $types, 'the cache layer does not self-root');
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
