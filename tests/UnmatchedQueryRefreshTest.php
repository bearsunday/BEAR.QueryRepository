<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\RefreshDest;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * onPost on a #[Cacheable] class whose parameters miss onGet's required ones (#219)
 *
 * A create whose parameters do not cover `onGet`'s required ones has no own entry to refresh:
 * `MatchQuery` throws `UnmatchedQuery`, and the write records the skip and still runs its `#[Purge]`.
 */
class UnmatchedQueryRefreshTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        RefreshDest::$id = 0;

        parent::setUp();
    }

    public function testPostSkipsTheRefreshInsteadOfThrowing(): void
    {
        $ro = $this->resource->post('app://self/mismatched-writer', ['title' => 'new']);

        $this->assertSame(200, $ro->code, 'the write itself still succeeds; only the automatic refresh cannot apply');
    }

    /** PHP method names are case-insensitive, so onPOST is the POST handler */
    public function testAPostHandlerSpelledInCapitalsSkipsTheRefreshToo(): void
    {
        $ro = $this->resource->post('app://self/case-post-writer', ['title' => 'new']);

        $this->assertSame(200, $ro->code);
        $error = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('"uri":"app://self/case-post-writer"', $error);
    }

    public function testPostStillRunsTheExplicitPurgeWrittenOnIt(): void
    {
        $dest = new Uri('app://self/refresh-dest?id=1');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '1']));
        $this->assertInstanceOf(ResourceState::class, $this->repository->get($dest));

        $this->resource->post('app://self/mismatched-writer', ['title' => 'new']);

        $this->assertNull($this->repository->get($dest), 'the skip does not stop the declared #[Purge]');
    }

    public function testTheSkipIsRecordedAsACacheError(): void
    {
        $this->resource->post('app://self/mismatched-writer', ['title' => 'sent-value-7f3a']);
        $tree = $this->flushAndValidate($this->logger);

        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the skip is a recorded event, not a silent one');
        $this->assertStringContainsString('"operation":"write"', $error);
        $this->assertStringContainsString('"exceptionClass":"BEAR\\\\QueryRepository\\\\Exception\\\\UnmatchedQuery"', $error);
        $this->assertStringNotContainsString('sent-value-7f3a', $error, 'the value the write was sent is not recorded');
        $this->assertStringContainsString('"uri":"app://self/mismatched-writer"', $error, 'the URI without its query');
    }

    public function testAPutThatThrowsDoesNotRunTheExplicitPurgeWrittenOnIt(): void
    {
        $dest = new Uri('app://self/refresh-dest?id=1');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '1']));

        try {
            $this->resource->put('app://self/mismatched-writer', ['title' => 'new']);
            $this->fail('a PUT whose query lacks onGet()\'s required parameter throws');
        } catch (UnmatchedQuery) {
        }

        $this->assertInstanceOf(ResourceState::class, $this->repository->get($dest), 'the PUT failed before its #[Purge] ran');
    }
}
