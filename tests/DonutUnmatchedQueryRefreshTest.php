<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

/**
 * onPost on a #[CacheableResponse] class whose parameters miss onGet's required ones (#219)
 *
 * The same gap as `UnmatchedQueryRefreshTest`, on the donut command path:
 * `DonutCommandInterceptor::refreshDonutAndState()` calls `MatchQuery` with no catch, inside
 * an `invoke()` whose `try` is `finally`-only. `DonutCacheModule` binds this interceptor to
 * `onPost` too (`commandMethods()`), so a `#[CacheableResponse]` collection POST whose
 * parameters do not cover `onGet`'s required ones threw uncaught here as well.
 */
class DonutUnmatchedQueryRefreshTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    public function testPostSkipsTheRefreshInsteadOfThrowing(): void
    {
        $ro = $this->resource->post('page://self/html/mismatched-donut-writer', ['title' => 'new']);

        $this->assertSame(200, $ro->code, 'the write itself still succeeds; only the automatic refresh cannot apply');
    }

    public function testTheSkipIsRecordedAsACacheError(): void
    {
        $this->resource->post('page://self/html/mismatched-donut-writer', ['title' => 'new']);
        $tree = $this->flushAndValidate($this->logger);

        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the skip is a recorded event, not a silent one');
        $this->assertStringContainsString('"operation":"write"', $error);
        $this->assertStringContainsString('"exceptionClass":"BEAR\\\\QueryRepository\\\\Exception\\\\UnmatchedQuery"', $error);
    }

    public function testPutStillThrowsForAGenuineMismatch(): void
    {
        $this->expectException(UnmatchedQuery::class);
        $this->resource->put('page://self/html/mismatched-donut-writer', ['title' => 'new']);
    }
}
