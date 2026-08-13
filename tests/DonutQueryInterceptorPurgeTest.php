<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

class DonutQueryInterceptorPurgeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepository $repository;
    private ResourceStorageInterface $storage;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof Injector);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepository::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->flushAndValidate($this->logger);
    }

    public function testStatePurge(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $this->assertSame(200, $ro1->code);
        $this->assertFalse($this->wasRecomposedFromDonut(), 'the first access creates the donut, it does not refresh one');
        $this->assertTrue($this->isStateCached());
        $puregeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($puregeResult);
        $this->assertFalse($this->isStateCached());

        $this->resource->get('page://self/html/blog-posting');
        $this->assertTrue($this->wasRecomposedFromDonut());
        $this->assertTrue($this->isStateCached(), 'Resource state should be cached');
    }

    public function testUnchangedRecompositionKeepsTheEntityTag(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $etag = $ro1->headers[Header::ETAG];
        $puregeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($puregeResult);

        $ro2 = $this->resource->get('page://self/html/blog-posting');
        $this->assertTrue($this->wasRecomposedFromDonut(), 'donut refresh should have run');
        // The entity-tag validates the representation, so a recomposition that yields
        // identical content must not change it — otherwise every refresh turns a client's
        // conditional request into a full 200 for content it already holds.
        $this->assertSame($etag, $ro2->headers[Header::ETAG]);
        $this->assertTrue(
            (new HttpCache($this->storage))->isNotModified([Header::HTTP_IF_NONE_MATCH => $etag]),
            'the pre-refresh validator still matches the stored ETag (304)',
        );
    }

    private function isStateCached(): bool
    {
        return $this->repository->get(new Uri('page://self/html/blog-posting')) instanceof ResourceState;
    }

    /**
     * Whether the page just fetched was recomposed from the cached donut
     *
     * Reads the fact from the semantic log (`refresh_donut`), which is where the
     * cache records it; the response itself carries no refresh marker — the ETag
     * stays a pure validator of the representation.
     */
    private function wasRecomposedFromDonut(): bool
    {
        $tree = $this->flushAndValidate($this->logger);

        return self::eventContextJsonOf($tree, 'refresh_donut') !== null;
    }
}
