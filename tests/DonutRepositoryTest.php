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
use function time;

class DonutRepositoryTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $queryRepository;
    private DonutRepositoryInterface $donutRepository;
    private Uri $uri;

    public function setUp(): void
    {
        static $injector;

        if (! $injector) {
            $injector = $this->getInjector();
        }

        assert($injector instanceof Injector);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $this->queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $uri = 'page://self/html/blog-posting';
        $this->uri = new Uri($uri);

        parent::setUp();
    }

    private function getInjector(): Injector
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        return new Injector($module, __DIR__ . '/tmp');
    }

    public function testCreateDonut(): void
    {
        $maybeNull = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNull);
        // assert cache created in query repository
        $blogPosting = $this->resource->get((string) $this->uri);
        $this->donutRepository->putStatic($blogPosting, null, null);
        $state = $this->queryRepository->get($this->uri);
        $this->assertInstanceOf(ResourceState::class, $state);
    }

    /** @depends testCreateDonut */
    public function testCachePurge(): void
    {
        $purgeResult = $this->queryRepository->purge($this->uri);
        $this->assertTrue($purgeResult);
        $maybeNullPurged = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNullPurged);
    }

    public function testCreatedByDonut(): void
    {
        // Own pools: the assertion is about a page recomposed from its donut, which a page
        // state left behind by another test method would serve instead
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // create donut
        $resource->get('page://self/html/blog-posting');
        // delete comment and blog-posting view
        $storage->invalidateTags([(new UriTag())(new Uri('page://self/html/comment'))]);
        // create by donut
        $donutRo = $resource->get('page://self/html/blog-posting');
        $this->assertSame(200, $donutRo->code);
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the page is recomposed from the cached donut');
        // Both donut-layer outcomes in one session: the cold GET missed the template, the
        // second one hit it after the page state was invalidated. Only the layer tells a
        // donut outcome apart from the resource one in the log.
        $miss = self::eventContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($miss);
        $this->assertStringContainsString('"layer":"donut"', $miss);
        $hit = self::eventContextJsonOf($tree, 'cache_hit');
        $this->assertNotNull($hit);
        $this->assertStringContainsString('"layer":"donut"', $hit);
    }

    /**
     * When cache A contains cache B, deleting B will automatically delete A as well.
     */
    public function testCacheDependency(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $queryRepository = $injector->getInstance(QueryRepository::class);

        // Cache created.
        $resource->get('page://self/html/blog-posting');
        $blogState1 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertInstanceOf(ResourceState::class, $blogState1);
        // When cache dependency is deleted, cache dependent automatically deleted
        $commentResult = $queryRepository->purge(new Uri('page://self/html/comment'));
        assert($commentResult);
        $blogState2 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertNull($blogState2);
        // Cache created again.
        $resource->get('page://self/html/blog-posting');
        $blogState3 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertInstanceOf(ResourceState::class, $blogState3);
    }

    public function testRefresh(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        $resource->get('page://self/html/blog-posting');
        $purgeResult = $queryRepository->purge(new Uri('page://self/html/comment'));
        $this->assertTrue($purgeResult);
        $donutRo = $resource->get('page://self/html/blog-posting');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the page is recomposed from the cached donut');
        $this->assertStringContainsString('blog-posting-page', $donutRo->headers[Header::SURROGATE_KEY]);
    }

    public function testInvalidateTags(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        /** @var QueryRepositoryInterface $queryRepository */
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        /** @var DonutRepositoryInterface $donutRepository */
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $resource->get('page://self/html/blog-posting');
        $ro1 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertInstanceOf(ResourceState::class, $ro1);
        $donutRepository->invalidateTags([(new UriTag())(new Uri('page://self/html/blog-posting'))]);
        $ro2 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertNull($ro2);
    }

    public function testTopLevelPutStaticIsRootedInManualStoreScope(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        $page = $resource->get('page://self/html/blog-posting');
        $logger->flush(); // drain the GET session: this is about the direct write that follows
        $donutRepository->putStatic($page, null, null);
        $tree = $this->flushAndValidate($logger);

        $this->assertNotNull(self::contextJsonOf($tree, 'manual_store'), 'a direct donut write is rooted as application-initiated');
        $close = self::closeContextJsonOf($tree, 'manual_store_result');
        $this->assertNotNull($close);
        $this->assertStringContainsString('"result":"stored"', $close);
        // Every event the write emits — the cleanup invalidation included, which therefore
        // no longer roots a manual_invalidate of its own — belongs to that one scope.
        $this->assertSame([], $tree['events'] ?? [], 'nothing is left bare at the session root');
        $this->assertSame(
            [['put_donut', 'pre_write_cleanup', 'invalidate', 'save_etag', 'save_donut_view', 'save_donut']],
            self::scopeEventTypeSequences($tree),
        );
    }

    public function testTopLevelPutDonutRecordsTheTemplateOnlyWrite(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        $page = $resource->get('page://self/html/blog-posting');
        $logger->flush(); // drain the GET session: this is about the direct write that follows
        $donutRepository->putDonut($page, 30);
        $tree = $this->flushAndValidate($logger);

        // The un-cacheable sibling of the write above: the page is never stored as a
        // rendered view, so the shape is the same minus the two page-level saves.
        $this->assertSame([], $tree['events'] ?? [], 'nothing is left bare at the session root');
        $this->assertSame(
            [['put_donut', 'pre_write_cleanup', 'invalidate', 'save_donut']],
            self::scopeEventTypeSequences($tree),
        );
    }

    public function testPutStaticInsideResourceGetOpensNoManualScope(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // BlogPostingCacheControl::onGet() calls putStatic() itself: nested in the GET scope
        // the interceptor opened, the write stays an ordinary sequence of events there.
        $resource->get('page://self/html/blog-posting-cache-control');
        $tree = $this->flushAndValidate($logger);

        $types = self::collectTypes($tree);
        $this->assertContains('put_donut', $types);
        $this->assertNotContains('manual_store', $types, 'a nested donut write is framework-driven');
        $this->assertNotContains('manual_store_result', $types);
        $this->assertNotContains('manual_invalidate', $types);
    }

    public function testRefreshRecordsTheSkippedStateWriteWhenTheTemplateLifetimeHasLapsed(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // A template entry the pool still answers with, five seconds past the lifetime it
        // was stored with: re-saving the recomposed content would restart a lifetime the
        // template no longer has, so the write is recorded as skipped instead.
        $donut = new ResourceDonut('cmt=[le:page://self/html/comment]', [], 30, true, null, null, time() - 5, ['lapsed-tag']);
        $storage->saveDonut($this->uri, $donut, 30, ['lapsed-tag']);

        $page = $resource->get((string) $this->uri);
        $tree = $this->flushAndValidate($logger);

        $this->assertSame(200, $page->code);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'));
        $saveDonut = self::eventContextJsonOf($tree, 'save_donut');
        $this->assertNotNull($saveDonut);
        $this->assertStringContainsString('"ttl":0', $saveDonut);
        $this->assertStringContainsString('"saved":false', $saveDonut);
    }

    public function testNegativeLifetimeIsRecordedAsAlreadyExpired(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // A caller asking for a lifetime that already passed: the storage stores 0, so the
        // recorded request says 0 too. Recording -1 would both contradict the save events
        // below it and violate the published schema, which flushAndValidate checks.
        $page = $resource->get((string) $this->uri);
        $logger->flush();
        $donutRepository->putStatic($page, -1, -1);
        $tree = $this->flushAndValidate($logger);

        $putDonut = self::eventContextJsonOf($tree, 'put_donut');
        $this->assertNotNull($putDonut);
        $this->assertStringContainsString('"ttl":0', $putDonut);
        $this->assertStringContainsString('"sMaxAge":0', $putDonut);
    }

    public function testDonutWriteBustsTheEntriesThatEmbedIt(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);

        // The blog posting embeds the comment, so its cached state carries the comment's URI
        // tag. Rewriting the comment cleans up that tag before saving, which is what keeps
        // the parent from serving a page built around the previous comment.
        $resource->get('page://self/html/blog-posting');
        $this->assertInstanceOf(ResourceState::class, $queryRepository->get($this->uri));

        $comment = $resource->get('page://self/html/comment');
        $donutRepository->putStatic($comment, null, null);

        $this->assertNull($queryRepository->get($this->uri));
    }

    public function testUncacheableDonutWriteDropsTheCachedState(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);

        // putDonut() stores the template only: the page is never served from a state again,
        // so the state a previous cacheable write left behind is cleaned up before the
        // template is saved. Skipping that cleanup would keep serving the stale page.
        $page = $resource->get((string) $this->uri);
        $this->assertInstanceOf(ResourceState::class, $queryRepository->get($this->uri));

        $donutRepository->putDonut($page, null);

        $this->assertNull($queryRepository->get($this->uri));
    }
}
