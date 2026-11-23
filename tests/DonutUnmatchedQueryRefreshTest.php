<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

/**
 * onPost on a #[CacheableResponse] class whose parameters miss onGet's required ones (#219)
 *
 * The same gap as `UnmatchedQueryRefreshTest`, on a `#[CacheableResponse]` collection POST.
 */
class DonutUnmatchedQueryRefreshTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    public function testPostSkipsTheRefreshInsteadOfThrowing(): void
    {
        $ro = $this->resource->post('page://self/html/mismatched-donut-writer', ['title' => 'new']);

        $this->assertSame(200, $ro->code, 'the write itself still succeeds; only the automatic refresh cannot apply');
    }

    /** PHP method names are case-insensitive, so onPOST is the POST handler */
    public function testAPostHandlerSpelledInCapitalsSkipsTheRefreshToo(): void
    {
        $ro = $this->resource->post('page://self/html/case-post-donut-writer', ['title' => 'new']);

        $this->assertSame(200, $ro->code);
        $error = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('"uri":"page://self/html/case-post-donut-writer"', $error);
    }

    public function testTheSkipIsRecordedAsACacheError(): void
    {
        $this->resource->post('page://self/html/mismatched-donut-writer', ['title' => 'sent-value-7f3a']);
        $tree = $this->flushAndValidate($this->logger);

        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the skip is a recorded event, not a silent one');
        $this->assertStringContainsString('"operation":"write"', $error);
        $this->assertStringContainsString('"exceptionClass":"BEAR\\\\QueryRepository\\\\Exception\\\\UnmatchedQuery"', $error);
        $this->assertStringNotContainsString('sent-value-7f3a', $error, 'the value the write was sent is not recorded');
        $this->assertStringContainsString('"uri":"page://self/html/mismatched-donut-writer"', $error, 'the URI without its query');
    }

    public function testPutStillThrowsForAGenuineMismatch(): void
    {
        $this->expectException(UnmatchedQuery::class);
        $this->resource->put('page://self/html/mismatched-donut-writer', ['title' => 'new']);
    }

    public function testAPostSkipsARefreshWhoseGetMakesAWriteThatDoesNotAddressItsOwn(): void
    {
        $ro = $this->resource->post('page://self/html/nested-mismatch-donut-writer', ['id' => 1]);

        $this->assertSame(200, $ro->code);
        $error = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('"uri":"page://self/html/nested-mismatch-donut-writer"', $error);
        $this->assertStringContainsString('"exceptionClass":"BEAR\\\\QueryRepository\\\\Exception\\\\UnmatchedQuery"', $error);
        $this->assertStringContainsString("put app://self/unmatch has no 'unused'", $error, 'the write the rebuilding GET made');
    }

    public function testAPutThrowsWhenTheRefreshGetMakesAWriteThatDoesNotAddressItsOwn(): void
    {
        $this->expectException(UnmatchedQuery::class);
        $this->resource->put('page://self/html/nested-mismatch-donut-writer', ['id' => 1]);
    }

    public function testAPutThatThrowsDoesNotRunTheExplicitPurgeWrittenOnIt(): void
    {
        $dest = new Uri('app://self/refresh-dest?id=1');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '1']));

        try {
            $this->resource->put('page://self/html/mismatched-donut-writer', ['title' => 'new']);
            $this->fail('a PUT whose query lacks onGet()\'s required parameter throws');
        } catch (UnmatchedQuery) {
        }

        $this->assertNotNull($this->repository->get($dest), 'the PUT failed before its #[Purge] ran');
    }

    /** A #[RefreshCache] write keeps the same promise: its #[Purge] runs only once its query addresses onGet() */
    public function testARefreshCachePutThatThrowsDoesNotRunThePurgeWrittenOnIt(): void
    {
        $dest = $this->storeRefreshDest();

        try {
            $this->resource->put('page://self/html/mismatched-refresh-cache-writer', ['title' => 'new']);
            $this->fail('a PUT whose query lacks onGet()\'s required parameter throws');
        } catch (UnmatchedQuery) {
        }

        $this->assertNotNull($this->repository->get($dest), 'the PUT failed before its #[Purge] ran');
    }

    public function testARefreshCachePutWhoseQueryAddressesOnGetRunsThePurgeWrittenOnIt(): void
    {
        $dest = $this->storeRefreshDest();

        $this->resource->put('page://self/html/mismatched-refresh-cache-writer', ['id' => '1', 'title' => 'new']);

        $this->assertNull($this->repository->get($dest));
    }

    public function testARefreshCachePostRunsThePurgeWrittenOnIt(): void
    {
        $dest = $this->storeRefreshDest();

        $ro = $this->resource->post('page://self/html/mismatched-refresh-cache-writer', ['title' => 'new']);

        $this->assertSame(200, $ro->code);
        $this->assertNull($this->repository->get($dest));
    }

    private function storeRefreshDest(): Uri
    {
        $dest = new Uri('app://self/refresh-dest?id=1');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '1']));
        $this->assertNotNull($this->repository->get($dest));

        return $dest;
    }
}
