<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
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
        $this->assertSame(['{"uri":"app://self/creating-writer?title=new"}'], self::eventContextsJsonOf($tree, 'purge_skipped'));
        $this->assertSame([], self::eventContextsJsonOf($tree, 'cache_error'), 'a creating write is not an error');
        $this->assertContains(['purge_skipped', 'purge', 'invalidate'], self::scopeEventTypeSequences($tree));
    }

    public function testAPutWhoseQueryAddressesNoKeyIsNotAnError(): void
    {
        $ro = $this->resource->put('app://self/creating-writer');

        $this->assertSame(200, $ro->code);
        $tree = $this->flushAndValidate($this->logger);
        $this->assertSame(['{"uri":"app://self/creating-writer"}'], self::eventContextsJsonOf($tree, 'purge_skipped'));
        $this->assertContains(['purge_skipped'], self::scopeEventTypeSequences($tree), 'nothing else to purge, so no invalidation');
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

    /** DonutCommandInterceptor purges and refreshes the resource; RefreshInterceptor purges only what #[Purge] names */
    public function testARefreshCacheWriteLeavesItsOwnKeyToTheDonutCommand(): void
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
            ['{"uri":"page://self/dep/level-two"}', '{"uri":"page://self/mx/DCRefreshPurge"}'],
            self::eventContextsJsonOf($tree, 'purge'),
            'its own key is purged once, by the donut command',
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
}
