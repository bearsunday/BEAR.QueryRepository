<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\Assisted\CacheableWriter;
use FakeVendor\HelloWorld\Resource\App\CacheableSelfPurgeWriter;
use FakeVendor\HelloWorld\Resource\App\Receipt;
use FakeVendor\HelloWorld\Resource\Page\Mx\DCRefreshPurge;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

/**
 * A write on a resource with no cache attribute invalidates its own key, together with what its #[Purge]/#[Refresh] name
 */
class OwnKeyWriteTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    public function testAPostWhoseQueryAddressesNoKeySkipsItsOwnAndStillPurgesWhatItDeclares(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $this->logger->flush();

        $this->resource->post('app://self/creating-writer', ['title' => 'new']);

        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-one')), 'the declared #[Purge] ran');
        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"app://self/creating-writer","reason":"unmatched_query"}'], self::eventContextsJsonOf($tree, 'purge_skipped'), 'the values the write was sent are not recorded');
        $this->assertSame([], self::eventContextsJsonOf($tree, 'cache_error'), 'a creating write is not an error');
        $this->assertContains(['purge_skipped', 'purge', 'invalidate'], self::scopeEventTypeSequences($tree));
    }

    public function testAPutWhoseQueryAddressesNoKeyIsNotAnError(): void
    {
        $ro = $this->resource->put('app://self/creating-writer');

        $this->assertSame(200, $ro->code);
        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"app://self/creating-writer","reason":"unmatched_query"}'], self::eventContextsJsonOf($tree, 'purge_skipped'));
        $this->assertContains(['purge_skipped'], self::scopeEventTypeSequences($tree), 'nothing else to purge, so no invalidation');
    }

    /** The key is the URI the write was sent to, whatever the handler does to its own */
    public function testAWriteThatChangesItsOwnUriPurgesTheKeyItWasSentTo(): void
    {
        $this->resource->put('app://self/uri-changing-writer', ['id' => '1']);

        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"app://self/uri-changing-writer?id=1"}'], self::eventContextsJsonOf($tree, 'purge'));
    }

    public function testAPurgeOfItselfIsOnePurgeOfItsOwnKey(): void
    {
        $this->resource->put('app://self/self-purge-writer', ['id' => '1']);

        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"app://self/self-purge-writer?id=1"}'], self::eventContextsJsonOf($tree, 'purge'), 'its own key and the #[Purge] of itself name one URI');
        $invalidations = self::eventContextsJsonOf($tree, 'invalidate');
        $this->assertCount(1, $invalidations);
        $this->assertStringContainsString('"tags":["_self-purge-writer_id=1","grp-_self-purge-writer_id=1"]', $invalidations[0]);
    }

    public function testACacheableWriteThatPurgesItselfKeepsTheRebuiltEntry(): void
    {
        CacheableSelfPurgeWriter::$gets = 0;
        $uri = 'app://self/cacheable-self-purge-writer';
        $this->resource->get($uri, ['id' => '1']);
        $this->logger->flush();

        $this->resource->put($uri, ['id' => '1']);

        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"app://self/cacheable-self-purge-writer?id=1"}'], self::eventContextsJsonOf($tree, 'purge'));
        $this->assertSame(2, CacheableSelfPurgeWriter::$gets, 'the write read its own URI again');
        $this->assertNotNull($this->repository->get(new Uri($uri . '?id=1')), 'the rebuilt entry is kept');
    }

    public function testAQueryRepositoryOfTheApplicationsOwnIsTheOneThatPurges(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            #[Override]
            protected function configure(): void
            {
                $this->bind(QueryRepositoryInterface::class)->to(FakePurgeRecordingQueryRepository::class);
            }
        });
        $resource = (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
        FakePurgeRecordingQueryRepository::$purged = [];

        $resource->put('app://self/self-purge-writer', ['id' => '1']);

        $this->assertSame(['app://self/self-purge-writer?id=1'], FakePurgeRecordingQueryRepository::$purged);
    }

    public function testARefreshCacheWritePurgesItsOwnKeyOnceAndRebuildsIt(): void
    {
        $uri = 'page://self/mx/DCRefreshPurge?id=1';
        $this->resource->get('page://self/dep/level-one');
        $this->resource->get($uri);
        $this->logger->flush();
        DCRefreshPurge::$gets = 0;

        $this->resource->put($uri);

        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-one')), 'the declared #[Purge] ran');
        $this->assertInstanceOf(ResourceDonut::class, $this->storage->getDonut(new Uri($uri)), 'the refreshed template is kept');
        $this->assertSame(1, DCRefreshPurge::$gets, 'only the refresh ran onGet()');
        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(
            ['{"uri":"page://self/mx/DCRefreshPurge"}', '{"uri":"page://self/dep/level-two"}'],
            self::eventContextsJsonOf($tree, 'purge'),
            'its own key is purged once',
        );
        $this->resource->get($uri);
        $this->assertSame(1, DCRefreshPurge::$gets, 'the next GET is served from the refreshed template');
    }

    /**
     * A hole runs only as the page renders, so it has no body when the page is stored, unless its class declares one:
     * then it reads as a material, and its key reaches the stored template.
     */
    public function testAHoleWhoseClassDeclaresABodyIsTakenAsAMaterial(): void
    {
        $this->resource->get('page://self/html/response-over-uncached-middle');

        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame([], self::skippedChildrenOf($tree, 'page://self/html/response-over-uncached-middle'));
        $saved = self::eventContextsJsonOf($tree, 'save_donut', 'page://self/html/response-over-uncached-middle');
        $this->assertCount(1, $saved);
        $this->assertStringContainsString('"_html_uncached-middle_"', $saved[0]);
    }

    /** A parameter #[ResourceParam] binds is not in the key a GET is stored under, so the write need not carry it */
    public function testAWriteNeedsNoResourceParamBoundValueToReachItsParent(): void
    {
        $parent = new Uri('app://self/assisted/writer-parent?id=1');
        $this->resource->get('app://self/assisted/writer-parent', ['id' => '1']);
        $this->assertNotNull($this->repository->get($parent));
        $this->logger->flush();

        $this->resource->put('app://self/assisted/writer', ['id' => '1']);

        $this->assertNull($this->repository->get($parent), 'the parent that embeds the writer is invalidated');
        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame([], self::eventContextsJsonOf($tree, 'purge_skipped'));
        $this->assertSame(['{"uri":"app://self/assisted/writer?id=1"}'], self::eventContextsJsonOf($tree, 'purge'));
    }

    public function testACacheableWriteNeedsNoResourceParamBoundValue(): void
    {
        CacheableWriter::$gets = 0;
        $this->resource->get('app://self/assisted/cacheable-writer', ['id' => '1']);

        $ro = $this->resource->put('app://self/assisted/cacheable-writer', ['id' => '1']);

        $this->assertSame(204, $ro->code);
        $this->assertSame(2, CacheableWriter::$gets, 'the write purged the entry and read it again');
        $this->assertNotNull($this->repository->get(new Uri('app://self/assisted/cacheable-writer?id=1')), 'refreshed');
    }

    /** The key is the resource the write was sent to; what it returns gives only the code */
    public function testAWriteThatReturnsAnotherResourcePurgesTheKeyOfTheOneItWrote(): void
    {
        $parent = new Uri('app://self/receipt-writer-parent?id=1');
        $this->resource->get('app://self/receipt-writer-parent', ['id' => 1]);
        $this->assertNotNull($this->repository->get($parent));

        $ro = $this->resource->put('app://self/receipt-writer', ['id' => 1]);

        $this->assertInstanceOf(Receipt::class, $ro);
        $this->assertSame(202, $ro->code);
        $this->assertNull($this->repository->get($parent), 'the parent that embeds the written resource is invalidated');
    }

    public function testAMethodLevelCacheableResponseWriteIsPurgedOnceAndRebuilt(): void
    {
        $uri = 'page://self/mx/MRWrite';
        $this->resource->get($uri, ['id' => '1']);
        $this->logger->flush();

        $this->resource->put($uri, ['id' => '1']);

        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"page://self/mx/MRWrite"}'], self::eventContextsJsonOf($tree, 'purge'));
        $this->assertNotNull($this->storage->getDonut(new Uri($uri . '?id=1')), 'the refreshed template is kept');
    }

    public function testAMethodLevelCacheableResponseWriteWithAPurgePurgesEachUriOnce(): void
    {
        $uri = 'page://self/mx/MRWrite';
        $this->resource->get($uri, ['id' => '1']);
        $this->logger->flush();

        $this->resource->post($uri, ['id' => '1']);

        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(
            ['{"uri":"page://self/mx/MRWrite"}', '{"uri":"page://self/dep/level-one"}'],
            self::eventContextsJsonOf($tree, 'purge'),
        );
        $this->assertNotNull($this->storage->getDonut(new Uri($uri . '?id=1')), 'the refreshed template is kept');
    }
}
