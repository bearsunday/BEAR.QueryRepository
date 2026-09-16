<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
 * #214 bound `CommandInterceptor` to `onPost` too, so `RefreshSameCommand`'s automatic
 * same-URI refresh now runs on every write - including a create whose parameters do not
 * cover `onGet`'s required ones. `MatchQuery` throws `UnmatchedQuery` for that case; before
 * this fix nothing caught it, so the write itself failed instead of just skipping a refresh
 * it cannot perform.
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

    public function testPostStillRunsTheExplicitPurgeWrittenOnIt(): void
    {
        $dest = new Uri('app://self/refresh-dest?id=1');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '1']));
        $this->assertInstanceOf(ResourceState::class, $this->repository->get($dest));

        $this->resource->post('app://self/mismatched-writer', ['title' => 'new']);

        $this->assertNull($this->repository->get($dest), 'CommandsProvider runs RefreshAnnotatedCommand next - the skip must not stop it');
    }

    public function testTheSkipIsRecordedAsACacheError(): void
    {
        $this->resource->post('app://self/mismatched-writer', ['title' => 'new']);
        $tree = $this->flushAndValidate($this->logger);

        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'the skip is a recorded event, not a silent one');
        $this->assertStringContainsString('"operation":"write"', $error);
        $this->assertStringContainsString('"exceptionClass":"BEAR\\\\QueryRepository\\\\Exception\\\\UnmatchedQuery"', $error);
    }
}
