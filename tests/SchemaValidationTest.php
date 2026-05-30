<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;
use function file_get_contents;
use function is_object;
use function json_decode;
use function json_encode;

/**
 * Drift-detection tests for the repository log schema
 *
 * Exercises real cache operations and asserts that every emitted log entry
 * validates against docs/schemas/repository-log.json, then proves the
 * validator actually rejects a schema-violating entry.
 */
class SchemaValidationTest extends TestCase
{
    use SchemaValidationTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private StructuredRepositoryLoggerInterface $logger;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $logger = $injector->getInstance(RepositoryLoggerInterface::class);
        assert($logger instanceof StructuredRepositoryLoggerInterface);
        $this->logger = $logger;

        parent::setUp();
    }

    public function testDependencyChainLogsValidateAgainstSchema(): void
    {
        // miss -> register dependencies -> save
        $this->resource->get('page://self/dep/level-one');
        // hit
        $this->resource->get('page://self/dep/level-one');
        // cascade invalidation
        $this->repository->purge(new Uri('page://self/dep/level-three'));
        // miss again (regenerated)
        $this->resource->get('page://self/dep/level-one');

        // Every emitted entry must conform to the published schema
        $this->assertLogValidatesSchema($this->logger);
        // Sanity: the run actually produced the canonical observability events
        $ops = $this->logger->getOps();
        $this->assertContains('cache-miss', $ops);
        $this->assertContains('cache-hit', $ops);
        $this->assertContains('depends-on', $ops);
    }

    public function testRefreshTriggerRecordsCommandCausality(): void
    {
        // User::onPut carries #[Purge] and #[Refresh]; the command is the cause of invalidation
        $this->resource->put('app://self/user', ['id' => 1, 'name' => 'bear', 'age' => 10]);

        $this->assertLogValidatesSchema($this->logger);
        $this->assertContains('refresh-trigger', $this->logger->getOps());

        $trigger = null;
        foreach ($this->logger->getLogs() as $log) {
            if ($log['op'] === 'refresh-trigger') {
                $trigger = $log;
                break;
            }
        }

        $this->assertNotNull($trigger);
        $this->assertSame('onPut', $trigger['method']);
        $this->assertNotEmpty($trigger['annotations']);
    }

    public function testSchemaRejectsUnknownOperation(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('totally-unknown-op', ['uri' => 'page://self/user']);

        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/schemas/repository-log.json'));
        assert(is_object($schema));
        $validator = new Validator();
        $entry = json_decode((string) json_encode($logger->getLogs()[0]));

        $this->assertFalse($validator->validate($entry, $schema)->isValid());
    }

    public function testSchemaRejectsCacheHitMissingLayer(): void
    {
        // cache-hit without the now-required "layer" field must be rejected
        $logger = new RepositoryLogger();
        $logger->log('cache-hit', ['uri' => 'page://self/user']);

        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/schemas/repository-log.json'));
        assert(is_object($schema));
        $validator = new Validator();
        $entry = json_decode((string) json_encode($logger->getLogs()[0]));

        $this->assertFalse($validator->validate($entry, $schema)->isValid());
    }
}
