<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\AkamaiCacheControlHeaderSetter;
use BEAR\QueryRepository\Cdn\FastlyCacheControlHeaderSetter;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\Scope;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function assert;
use function basename;
use function class_exists;
use function dirname;
use function glob;
use function is_string;
use function is_subclass_of;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
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
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

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

    public function testUnstorableMarkSkipsDonutStoreAndOrdinaryResponsesStillStore(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // Ordinary (unmarked) responses store and hit normally - checked first, since the
        // mark is per-request state (here: one shared container for the whole test) that
        // cannot be unset once a later call sets it.
        $ordinary = $resource->get('page://self/html/unstorable-donut', ['unstorable' => false]);
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $ordinary->headers);
        $logger->flush();
        $resource->get('page://self/html/unstorable-donut', ['unstorable' => false]);
        $treeOrdinary = $this->flushAndValidate($logger);
        $this->assertContains('cache_hit', self::collectTypes($treeOrdinary));

        // A producer (a CSRF token issuer) marks this request's response unstorable: the
        // donut write is skipped, the live response is still served, and no CDN header is
        // emitted (issue #222).
        $ro = $resource->get('page://self/html/unstorable-donut');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);

        $tree = $this->flushAndValidate($logger);
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped);
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);

        // Nothing was stored: a second GET of the same (still marked) request misses again.
        $logger->flush();
        $resource->get('page://self/html/unstorable-donut');
        $tree2 = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree2, 'cache_miss'));
        $this->assertNull(self::eventContextJsonOf($tree2, 'cache_hit'));
    }

    public function testRefreshSkipsSavingContentAndCdnHeaderWhenMarkedUnstorable(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // Populate the donut template and page state first (ordinary write).
        $resource->get('page://self/html/blog-posting');
        // Drop just the page state - same mechanism testCreatedByDonut uses - leaving the
        // template behind, so the next GET takes the refresh path.
        $storage->invalidateTags([(new UriTag())(new Uri('page://self/html/comment'))]);
        $logger->flush();

        // A hole marked this recomposition unstorable - e.g. it issued a CSRF token.
        // ResourceDonut::refresh() restores the stored headers afterwards, but the mark is
        // a separate per-request service and survives that (issue #222).
        $mark->mark('csrf-token-issued');
        $donutRo = $resource->get('page://self/html/blog-posting');

        $this->assertSame('private, no-store', $donutRo->headers[Header::CACHE_CONTROL]);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $donutRo->headers);

        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the page is recomposed from the cached donut');
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped);
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);
    }

    public function testDonutCacheTemplateSkipsSaveWhenThePageMarksItsOwnBody(): void
    {
        // A #[DonutCache] template holds only placeholders for an embedded hole's content -
        // but a page can also write a per-user value straight into its own body/view,
        // outside any hole, and that lands in the stored template verbatim (issue #222).
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // Ordinary (unmarked) first - the shared mark is sticky once set. A normal
        // #[DonutCache] page still saves its template: the next GET recomposes from it
        // (refresh_donut), not a fresh donut miss.
        $resource->get('page://self/html/unstorable-donut-cache-template', ['unstorable' => false]);
        $logger->flush();
        $resource->get('page://self/html/unstorable-donut-cache-template', ['unstorable' => false]);
        $treeOrdinary = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($treeOrdinary, 'refresh_donut'), 'an ordinary #[DonutCache] page still saves and reuses its template');

        // The page marks itself unstorable: the template write must be refused - otherwise
        // the secret value, baked verbatim into the stored template, is served to every
        // later visitor.
        $ro = $resource->get('page://self/html/unstorable-donut-cache-template');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);

        $tree = $this->flushAndValidate($logger);
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped);
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);

        // Nothing was stored: a second GET of the same (still marked) request is a fresh
        // donut miss again, not a refresh_donut recomposed from a leaked template.
        $logger->flush();
        $resource->get('page://self/html/unstorable-donut-cache-template');
        $tree2 = $this->flushAndValidate($logger);
        $this->assertNull(self::eventContextJsonOf($tree2, 'refresh_donut'));
        $this->assertNotNull(self::eventContextJsonOf($tree2, 'cache_miss'));
    }

    public function testDonutCacheTemplateStoresWhenOnlyAnEmbeddedHoleMarksItself(): void
    {
        // #[DonutCache] templates hold placeholders only - a hole's content is substituted
        // at render/refresh time, never baked into the stored template - so a hole marking
        // itself unstorable (its own storage is separately gated by its own #[Cacheable]
        // write) must not block the parent template write: this is exactly the "a page
        // whose holes contain a form" case issue #222 explicitly keeps working. The live
        // response is a different matter - the hole's rendered output IS substituted into
        // it, so it still reads Cache-Control: private, no-store (issue #222 MEDIUM) - on
        // BOTH the response that wrote the template (cache_miss, doPutDonut) AND every
        // later one recomposed from it (cache_hit, refreshDonut): the mark is still set
        // for this request (UncacheableMark is a per-injector singleton), and that refusal
        // must be read on recomposition too, not just the response that first wrote the
        // template.
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $ro = $resource->get('page://self/html/donut-cache-with-unstorable-hole');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
        $logger->flush();
        $refreshed = $resource->get('page://self/html/donut-cache-with-unstorable-hole');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the template was saved despite the embedded hole marking itself');
        $this->assertSame('private, no-store', $refreshed->headers[Header::CACHE_CONTROL], 'the recomposed (refresh) response must still refuse CDN caching, not just the one that first wrote the template');
    }

    public function testDonutCacheWithAMultiParamEmbedRendersAndRefreshesBothQueryParams(): void
    {
        // End-to-end through the real #[Embed] pipeline (URI-template expansion, not a
        // manually built request), through the project's standard `{{ hole }}` (no
        // `|raw`): the donut placeholder/view mapping and a later
        // ResourceDonut::refresh() must carry and re-resolve BOTH query parameters, not
        // just a single one, and the placeholder itself must survive Twig's default
        // HTML-escaping of its `&` (issue #232).
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $first = $resource->get('page://self/html/donut-cache-with-multi-param-query-hole');
        $this->assertStringNotContainsString('[le:', (string) $first->view, 'the escaped placeholder must be substituted, not leaked raw to the client');
        $this->assertStringContainsString('1-2', (string) $first->view, 'both query params resolved on the first (render) GET');

        // Drop just the hole's own state, leaving the page's donut template behind, so the
        // next GET takes the refresh path: ResourceDonut::refresh() re-parses the stored
        // [le:URI] placeholder and re-fetches it independently of the first render.
        $storage->invalidateTags([(new UriTag())(new Uri('app://self/multi-param-query-hole?id=1&tag=2'))]);
        $logger->flush();

        $refreshed = $resource->get('page://self/html/donut-cache-with-multi-param-query-hole');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the page is recomposed from the cached donut');
        $this->assertStringContainsString('1-2', (string) $refreshed->view, 'refresh() re-fetched the hole by its full, multi-param URI, not a partial or query-less one');
    }

    public function testNestedDonutCacheHoleDoesNotLeakItsOwnMarkedContentToTheSharedCache(): void
    {
        // The hole (RenderTimeMark, itself #[DonutCache]) runs its own write while still
        // nested inside this page's enterHole() scope; its own render-time mark must refuse
        // only ITS OWN template - a flat "inside any hole" flag would misattribute that mark
        // to "inside a hole" from the hole's own perspective too, and store the leak.
        //
        // One shared injector/container for both GETs, not two: the mark is request-scoped
        // state that legitimately differs per request, but the underlying storage pool must
        // be the SAME one a later visitor's request would actually share - two separate
        // injectors each get their own in-memory ArrayAdapter (ModuleFactory/FakeEtagPoolModule),
        // which would make the second GET below a cold miss regardless of whether the bug
        // exists, proving nothing.
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $resource->get('page://self/html/donut-cache-with-nested-donut-cache-hole');
        $logger->flush();

        // A later, different visitor requesting the hole's own URI directly, against the
        // same shared cache this GET also reads. If the hole's template had been stored
        // with the leaked content, this would be a hit serving it.
        $ro = $resource->get('page://self/html/render-time-mark');

        $tree = $this->flushAndValidate($logger);
        $this->assertNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'nothing was stored for this later visitor to recompose from');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testRefreshReSavesAnUnrelatedNestedDonutCacheHolesTemplateAfterASiblingHoleMarksDuringTheRefresh(): void
    {
        // ResourceDonut::refresh() re-fetches each placeholder with $resource->get($uri);
        // without scoping that re-fetch to its own URI the way DonutRequest::__toString()
        // scopes the MISS path, a sibling hole's own mark (UnstorableValue marks every
        // request) is attributed to TOP_LEVEL during the refresh - and an UNRELATED nested
        // #[DonutCache] hole (PlainDonutHole, which never marks) reads
        // isMarkedForCurrentScope() as true for its own write and wrongly refuses to
        // re-save its own purged template (issue #222).
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // First GET: MISS. Composes the outer page's own template and, via its own
        // nested write, PlainDonutHole's own template too.
        $resource->get('page://self/html/donut-cache-with-marking-and-nested-donut-cache-hole');
        $logger->flush();

        // Drop only the unrelated nested hole's own template, so the outer page's next
        // GET - always a refresh for a #[DonutCache] page, which never stores its own
        // state - re-fetches it as a fresh MISS that must re-save it.
        $storage->invalidateTags([(new UriTag())(new Uri('page://self/html/plain-donut-hole'))]);

        $resource->get('page://self/html/donut-cache-with-marking-and-nested-donut-cache-hole');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the outer page is recomposed from its own cached donut');
        $this->assertTrue($mark->isMarked(), 'sanity: the sibling hole did mark during this refresh');

        // A later visitor requesting the unrelated hole directly: if its own template was
        // re-saved (the fix), this is a refresh (template hit); if the sibling's mark was
        // misattributed to it instead (the bug), this is a fresh MISS again.
        $logger->flush();
        $resource->get('page://self/html/plain-donut-hole');
        $laterTree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($laterTree, 'refresh_donut'), "the unrelated hole's own purged template was re-saved during the sibling's marked refresh, not skipped because of the sibling's mark");
    }

    public function testRefreshStillRefusesANestedDonutCacheHolesOwnTemplateWhenItMarksItselfDuringTheRefresh(): void
    {
        // Same safety property as testNestedDonutCacheHoleDoesNotLeakItsOwnMarkedContentToTheSharedCache
        // above, but reached through ResourceDonut::refresh() instead of the initial
        // compose (DonutRequest): enterHole()/leaveHole() around the refresh re-fetch must
        // still let the hole's own render-time mark refuse ITS OWN template, the same way
        // it does on the MISS path - the fix for the unrelated-sibling case above must not
        // loosen this.
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // First GET composes the outer page's own template (MISS); RenderTimeMark marks
        // itself every time, so its own write is refused here too.
        $resource->get('page://self/html/donut-cache-with-nested-donut-cache-hole');
        $logger->flush();

        // A #[DonutCache] page never stores its own state, so this second GET is always a
        // refresh - re-fetching RenderTimeMark through ResourceDonut::refresh(), not
        // DonutRequest.
        $resource->get('page://self/html/donut-cache-with-nested-donut-cache-hole');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the outer page is recomposed from its own cached donut');

        // A later, different visitor requesting the hole's own URI directly. If the
        // hole's template had been stored with the leaked content during the refresh
        // above, this would be a hit serving it.
        $ro = $resource->get('page://self/html/render-time-mark');
        $laterTree = $this->flushAndValidate($logger);
        $this->assertNull(self::eventContextJsonOf($laterTree, 'refresh_donut'), "nothing was stored for this later visitor to recompose from, even though the hole's own mark fired during the OUTER page's refresh");
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testDonutCacheTemplateSkipsSaveWhenMarkedWhileItsOwnTemplateRenders(): void
    {
        // The mark can also happen while the page's own template renders (e.g. a Twig CSRF
        // helper), inside the same ResourceDonut::create() call that resolves any embedded
        // hole - neither in onGet() (too early) nor inside a hole's own execution (wrongly
        // exempt). Both the read order and the hole-exclusion must get this right (issue #222).
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $ro = $resource->get('page://self/html/render-time-mark');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);

        $tree = $this->flushAndValidate($logger);
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped);
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);

        // Nothing was stored: a second GET is a fresh donut miss again, not a refresh_donut
        // recomposed from a leaked template.
        $logger->flush();
        $resource->get('page://self/html/render-time-mark');
        $tree2 = $this->flushAndValidate($logger);
        $this->assertNull(self::eventContextJsonOf($tree2, 'refresh_donut'));
        $this->assertNotNull(self::eventContextJsonOf($tree2, 'cache_miss'));
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
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

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
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

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
        $this->assertSame([], self::rootPointTypes($tree), 'nothing is left bare at the session root');
        $this->assertSame(
            [['put_donut', 'cdn_headers', 'pre_write_cleanup', 'invalidate', 'save_etag', 'save_donut_view', 'save_donut']],
            self::scopeEventTypeSequences($tree),
        );
    }

    public function testTopLevelPutDonutRecordsTheTemplateOnlyWrite(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $page = $resource->get('page://self/html/blog-posting');
        $logger->flush(); // drain the GET session: this is about the direct write that follows
        $donutRepository->putDonut($page, 30);
        $tree = $this->flushAndValidate($logger);

        // The un-cacheable sibling of the write above: the page is never stored as a
        // rendered view, so the shape is the same minus the two page-level saves.
        $this->assertSame([], self::rootPointTypes($tree), 'nothing is left bare at the session root');
        $this->assertSame(
            [['put_donut', 'cdn_headers', 'pre_write_cleanup', 'invalidate', 'save_donut']],
            self::scopeEventTypeSequences($tree),
        );
    }

    public function testPutStaticInsideResourceGetOpensNoManualScope(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

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
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // A template entry the pool still answers with, five seconds past the lifetime it
        // was stored with: re-saving the recomposed content would restart a lifetime the
        // template no longer has, so the write is recorded as skipped instead.
        $donut = new ResourceDonut('cmt=[le:page://self/html/comment]', [], 30, true, null, null, time() - 5, ['lapsed-tag']);
        $storage->saveDonut($this->uri, $donut, 30, ['lapsed-tag']);
        $logger->flush(); // drain the seed write: this test is about the refresh that follows, not the setup
        $page = $resource->get((string) $this->uri);
        $tree = $this->flushAndValidate($logger);

        $this->assertSame(200, $page->code);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'));
        $saveDonut = self::eventContextJsonOf($tree, 'save_donut');
        $this->assertNotNull($saveDonut);
        $this->assertStringContainsString('"requestedTtl":0', $saveDonut);
        $this->assertStringContainsString('"saved":false', $saveDonut);
    }

    public function testNegativeLifetimeIsRecordedAsTheZeroItIsStoredWith(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // A lifetime at or below zero is not a lifetime the storage can set, so the entry is
        // stored with no expiry and the request is recorded as the 0 that means exactly that.
        // Recording -1 would contradict the save events below it and violate the published
        // schema, which flushAndValidate checks.
        $page = $resource->get((string) $this->uri);
        $logger->flush();
        $donutRepository->putStatic($page, -1, -1);
        $tree = $this->flushAndValidate($logger);

        $putDonut = self::eventContextJsonOf($tree, 'put_donut');
        $this->assertNotNull($putDonut);
        $this->assertStringContainsString('"requestedTtl":0', $putDonut);
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

    public function testManualDonutWriteClosesAsFailedWhenTheStoreThrows(): void
    {
        // The page comes from healthy pools, so only the write below meets the outage.
        $page = $this->getInjector()->getInstance(ResourceInterface::class)->get((string) $this->uri);

        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false, throwOnSave: true);
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($pool);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // A direct donut write has no interceptor to degrade it, so the outage surfaces here.
        // Its scope must close as failed: closing `stored` while the caller catches an
        // exception is the log claiming a write that did not happen.
        try {
            $donutRepository->putStatic($page, null, null);
            $this->fail('expected the pool outage to surface from a direct donut write');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cache server down', $e->getMessage());
        }

        $tree = $this->flushAndValidate($logger);
        $close = self::closeContextJsonOf($tree, 'manual_store_result');
        $this->assertNotNull($close, 'the scope closes even though the write threw');
        $this->assertStringContainsString('"result":"failed"', $close);
    }

    /**
     * A 301 is stored and replayed as a 301
     *
     * The donut save gate skips 4xx and above only, so a 301 is stored, and the state
     * carries the code. Until it was restored the page answered 200 - with the Location
     * header still attached - from the second request on.
     */
    public function testRedirectStatusSurvivesTheCacheHit(): void
    {
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        $miss = $resource->get('page://self/html/redirect-page');
        $this->assertSame(301, $miss->code, 'first request, cache miss');
        $hit = $resource->get('page://self/html/redirect-page');
        $this->assertSame(301, $hit->code, 'second request, cache hit');
        $this->assertSame('/html/blog-posting?id=0', $hit->headers['Location']);
    }

    /**
     * A stored 301 carries no validator and no CDN lifetime, but stays purgeable
     *
     * A validator on a 301 would let ConditionalResponse::isModified() answer 304 in its
     * place, and the Fastly and Akamai lifetime defaults are a year. Surrogate-Key is the
     * one header a stored 3xx does need: without it no invalidateTags() call reaches the
     * entry, and the redirect outlives the reason for it.
     */
    public function testStoredRedirectCarriesNoValidatorAndNoCdnLifetime(): void
    {
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        $responses = ['cache miss' => $resource->get('page://self/html/redirect-page'), 'cache hit' => $resource->get('page://self/html/redirect-page')];
        foreach ($responses as $when => $ro) {
            $this->assertArrayNotHasKey(Header::ETAG, $ro->headers, $when);
            $this->assertArrayNotHasKey(Header::LAST_MODIFIED, $ro->headers, $when);
            $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers, $when);
            $this->assertArrayHasKey(Header::SURROGATE_KEY, $ro->headers, $when);
        }
    }

    /**
     * A 202 recomposed from its donut template is still a 202
     *
     * Purging the embedded child drops the stored page view but not the template, so this
     * request is answered by refreshDonut() - the path where the donut, not the page
     * state, is what has to carry the code, and the one that would hand a shared cache a
     * lifetime for a response that is not a 200.
     *
     * The page answers 202 rather than 301 on purpose: TwigRenderer replaces a redirect's
     * own template with its redirect page (TwigRenderer::isRedirect()), so a 301 composes
     * no children, its view is tagged exactly like its template, and no purge can reach
     * one without the other. A 2xx renders normally and can.
     */
    public function testAcceptedStatusSurvivesTheDonutRefresh(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $resource->get('page://self/html/accepted-page');
        $this->assertTrue($queryRepository->purge(new Uri('page://self/html/comment')));
        $refreshed = $resource->get('page://self/html/accepted-page');

        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the page is recomposed from the cached donut');
        $this->assertSame(202, $refreshed->code);
        $this->assertArrayNotHasKey(Header::ETAG, $refreshed->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $refreshed->headers);
    }

    /**
     * A 204 with no body is stored and replayed as a 204
     *
     * A resource that has nothing to return leaves the body null, which ResourceDonut
     * rejected before the status code ever came into it.
     */
    public function testNoContentStatusSurvivesTheCacheHit(): void
    {
        $resource = $this->getInjector()->getInstance(ResourceInterface::class);

        $this->assertSame(204, $resource->get('page://self/html/no-content-page')->code, 'first request, cache miss');
        $this->assertSame(204, $resource->get('page://self/html/no-content-page')->code, 'second request, cache hit');
    }

    /**
     * The same 301 under #[DonutCache] is still a 301 on the next request
     *
     * This path stores the template only, so every later request is a refresh. It never
     * threw: it answered 200 with the Location header attached, which is the quieter half
     * of the same missing restore.
     */
    public function testDonutCacheRedirectStatusSurvivesTheRefresh(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $this->assertSame(301, $resource->get('page://self/html/redirect-donut')->code, 'first request');
        $this->assertSame(301, $resource->get('page://self/html/redirect-donut')->code, 'second request');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the second request is answered by the template refresh');
    }

    public function testEveryBuiltInCdnHeaderIsInTheRecordedSet(): void
    {
        // cdn_headers records the headers named in DonutRepository::CDN_FACING_HEADERS.
        // That list is a convention, and a built-in setter added without extending it
        // would silently vanish from the log - this closes the set by reflection: every
        // string constant a bound-able setter declares is a header the log must know.
        $known = (new ReflectionClass(DonutRepository::class))->getConstant('CDN_FACING_HEADERS');
        $this->assertIsArray($known);

        $setters = [];
        // Two explicit globs, not GLOB_BRACE: a brace pattern that does not expand returns
        // fewer files without an error, which would silently skip the Cdn/ half.
        foreach (['' => '/src/*.php', 'Cdn\\' => '/src/Cdn/*.php'] as $prefix => $pattern) {
            foreach ((array) glob(dirname(__DIR__) . $pattern) as $file) {
                $class = 'BEAR\QueryRepository\\' . $prefix . basename((string) $file, '.php');
                if (class_exists($class) && is_subclass_of($class, CdnCacheControlHeaderSetterInterface::class)) {
                    $setters[] = $class;
                }
            }
        }

        // Both halves must be proven scanned: the default setter lives in src/, the flavors
        // in src/Cdn/ - asserting only the former would let the Cdn/ half find nothing.
        $this->assertContains(CdnCacheControlHeaderSetter::class, $setters, 'discovery sees the default setter');
        $this->assertContains(FastlyCacheControlHeaderSetter::class, $setters, 'discovery sees the Cdn/ directory');
        $this->assertContains(AkamaiCacheControlHeaderSetter::class, $setters, 'discovery sees the Cdn/ directory');
        foreach ($setters as $setter) {
            foreach ((new ReflectionClass($setter))->getConstants() as $name => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->assertContains($value, $known, "$setter::$name names a CDN-facing header the log does not record");
            }
        }
    }

    public function testAnUnrelatedCacheableSiblingAfterAMarkingHoleIsStoredNotRefused(): void
    {
        // The literal #234 repro: UnstorableValue marks every request; InnocentCacheableSibling,
        // an entirely unrelated #[Cacheable] resource embedded as the NEXT sibling hole, never
        // marks. Its own write must be stored regardless of the sibling's mark.
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $resource->get('page://self/html/donut-cache-with-marking-hole-and-cacheable-sibling');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'put_skipped'), 'sanity: the marking hole still refuses its own write, as before #234');

        // The real assertion: a later, different visitor requesting the unrelated sibling's
        // own URI directly hits the stored entry - it was NOT refused because of the
        // sibling's mark.
        $logger->flush();
        $ro = $resource->get('app://self/innocent-cacheable-sibling');
        $laterTree = $this->flushAndValidate($logger);
        $this->assertContains('cache_hit', self::collectTypes($laterTree), 'the unrelated sibling\'s own entry was stored, not refused by the marking hole (#234)');
        // HeaderSetter must agree with the store: a mutation to the any-owner predicate there
        // (Kimi review) would still pass the cache_hit assertion above (the entry WAS stored)
        // while the response header still read as refused - stored:true, private:no-store is
        // the inconsistency #234's own HeaderSetter fix exists to prevent.
        $this->assertArrayHasKey(Header::ETAG, $ro->headers, 'a stored #[Cacheable] entry carries a validator, not a private/no-store response');
        $this->assertNotSame('private, no-store', $ro->headers[Header::CACHE_CONTROL] ?? null);
    }

    public function testACacheableViewEmbeddingAnIndependentlyDonutComposedMarkingHoleIsNotStoredCompoundLeak(): void
    {
        // #234 rejected fix #1 (rescoping doPut() to isMarkedForCurrentScope() ONLY): a
        // #[Cacheable] (type: 'view') page P embeds D (DonutCacheWithUnstorableHole, itself
        // #[DonutCache]), whose own hole H (UnstorableValue) marks. H's mark is recorded
        // under H's own owner, never P's - a scope-only check on P would never see it and
        // would store P's rendered template with H's secret baked in verbatim.
        // wasMarkedSinceCurrentWrite() is what refuses P: H marked DURING P's own open write
        // window (P's own toString() call, inside doPut()).
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $ro = $resource->get('page://self/html/cacheable-view-embedding-marking-donut');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL], 'the compound leak must be refused, not just the inner hole');
        $this->assertStringContainsString('secret', (string) $ro->view, 'sanity: the secret IS baked into the rendered view - the leak is real if this write is not refused');

        $tree = $this->flushAndValidate($logger);
        // Several writes skip in this scenario (H's own, and P's own) - find P's own
        // specifically, not just the first skip in the tree.
        $ownSkip = null;
        foreach (self::eventContextsJsonOf($tree, 'put_skipped') as $json) {
            if (str_contains($json, 'cacheable-view-embedding-marking-donut')) {
                $ownSkip = $json;
            }
        }

        $this->assertNotNull($ownSkip, 'P\'s own write (not just H\'s own) must be recorded as skipped');
        $this->assertStringContainsString('"reason":"unstorable"', $ownSkip);

        // Nothing was stored: a second GET of P is a fresh MISS again (its own outer close),
        // not a hit serving the leak - D's own, independently-cached entry legitimately hits
        // on this second GET too, so this checks P's OWN close specifically, not a blanket
        // tree-wide cache_hit absence.
        $logger->flush();
        $resource->get('page://self/html/cacheable-view-embedding-marking-donut');
        $tree2 = $this->flushAndValidate($logger);
        $this->assertNotNull(self::closeContextJsonOf($tree2, 'cache_miss'), 'the page\'s own outer GET must still be a MISS, not a hit serving the leak');
        $this->assertStringContainsString('"layer":"resource"', self::closeContextJsonOf($tree2, 'cache_miss'), 'the outermost close (the page\'s own, via CacheInterceptor) must be the miss, not a deeper donut-layer one');
    }

    public function testBeMartShapedProductPageStoresStockWhenTheCartFormHoleComesBeforeTheStockHoleOrderA(): void
    {
        // BeMart-shaped #234 repro: page://self/product embeds the cart-form hole (marks,
        // CSRF) BEFORE the cacheable stock hole ("order A" - the order that actually triggers
        // #234; the real app's own stock-before-form order happens to warm the stock write
        // before the mark fires, masking the bug by accident - this fixture does not rely on
        // that).
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);

        $ro = $resource->get('page://self/product', ['productCode' => 'sample-001']);
        $this->assertSame(200, $ro->code);
        $this->assertTrue($mark->isMarked(), 'sanity: the cart-form hole did mark during this request');

        $tree = $this->flushAndValidate($logger);
        $this->assertNull(self::eventContextJsonOf($tree, 'put_skipped'), 'nothing was refused on this page - the form hole is not itself cacheable, and #234 must not make the page template or the stock hole look refused either');

        // The real assertion: stock is now stored - no put_skipped on app://self/product/stock.
        $logger->flush();
        $stockRo = $resource->get('app://self/product/stock', ['productCode' => 'sample-001']);
        $laterTree = $this->flushAndValidate($logger);
        $this->assertContains('cache_hit', self::collectTypes($laterTree), 'app://self/product/stock must be stored, not refused by the form hole\'s mark (#234)');
        $this->assertNull(self::eventContextJsonOf($laterTree, 'put_skipped'), 'no put_skipped on app://self/product/stock');
        // Same HeaderSetter-agreement guard as the sibling test above: a stored entry must not
        // also read back as refused (private, no-store, no validator).
        $this->assertArrayHasKey(Header::ETAG, $stockRo->headers, 'a stored #[Cacheable] entry carries a validator, not a private/no-store response');
        $this->assertNotSame('private, no-store', $stockRo->headers[Header::CACHE_CONTROL] ?? null);
    }

    public function testConstructorDefaultsWriteObserverSoAPre1180CallerPassingOnlyNineArgumentsStillConstructs(): void
    {
        // The constructor argument count went 9->10 adding $writeObserver (#234 dev gate),
        // inserted before the already-defaulted $uriTag - a caller built against the released
        // 9-argument signature, still omitting both trailing arguments, must not TypeError.
        $constructor = (new ReflectionClass(DonutRepository::class))->getConstructor();
        assert($constructor !== null);
        $parameters = $constructor->getParameters();
        $writeObserverParam = $parameters[9];
        $this->assertSame('writeObserver', $writeObserverParam->getName());
        $this->assertTrue($writeObserverParam->isOptional(), 'a pre-1.18.0 9-argument caller must still construct');
        $this->assertTrue($writeObserverParam->isDefaultValueAvailable());
        $this->assertInstanceOf(NullUnstorableWriteObserver::class, $writeObserverParam->getDefaultValue());
    }

    public function testDiActuallyWiresTheBoundObserverIntoDonutRepositoryNotJustThePhpDefault(): void
    {
        // The donut-interceptor twin of QueryRepositoryTest's own copy of this guard: a
        // missing or shadowed UnstorableWriteObserverInterface binding must not silently fall
        // through to the constructor default - proven by identity between the instance DI
        // actually injected into DonutRepository (private property, via Reflection) and the
        // instance the same injector resolves directly.
        $injector = $this->getInjector();
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($donutRepository instanceof DonutRepository);
        $observer = $injector->getInstance(UnstorableWriteObserverInterface::class);

        $property = (new ReflectionClass(DonutRepository::class))->getProperty('writeObserver');
        $property->setAccessible(true);
        $this->assertSame($observer, $property->getValue($donutRepository));
    }

    public function testADegradedReadFallbackThatNowStoresIsNotBlockedByAnUnrelatedEarlierHoleMark(): void
    {
        // #237 sends the donut degraded-read fallback through handleProceedResult(), i.e. a
        // real TEMPLATE store attempt (putRecorded() -> doPutDonut()) - merge round addition.
        // That store is judged by reasonExcludingHoleMarks() (isMarkedForCurrentScope(),
        // pre-#234, issue #222): a hole's own mark, from an EARLIER, already-closed request
        // to an entirely different page, must not block THIS unrelated, non-marking page's
        // own fallback template write - the mark is attributed to the hole's own owner slot,
        // never this page's own (TOP_LEVEL) scope.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepositoryInterface::class)->to(FakeDonutReadFailsForOneUri::class)->in(Scope::SINGLETON);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $fakeDonutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($fakeDonutRepository instanceof FakeDonutReadFailsForOneUri);

        // The unrelated, EARLIER mark: a different page's own marking hole (UnstorableValue,
        // inside DonutCacheWithMarkingHoleAndCacheableSibling), fully closed
        // (enterHole()/leaveHole()) before this test's own target page is ever touched.
        $resource->get('page://self/html/donut-cache-with-marking-hole-and-cacheable-sibling');
        $logger->flush();

        // The target: a plain, never-marking #[DonutCache] page whose own donutRepository
        // ->get() degrades, forcing the NEW fallback path (render + store via
        // handleProceedResult()/putRecorded()).
        $fakeDonutRepository->failingUri = 'page://self/html/blog-posting-donut';
        set_error_handler(static fn (): bool => true); // the degraded-read warning still fires
        try {
            $ro = $resource->get('page://self/html/blog-posting-donut');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $ro->code);
        $tree = $this->flushAndValidate($logger);
        // Sanity: the fake's read actually degraded (not a silently-vacuous normal MISS) -
        // without this, a URI mismatch (e.g. a query string FakeDonutReadFailsForOneUri's
        // exact-string match missed) would let the test pass without ever reaching the
        // fallback path under test.
        $readError = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($readError, 'sanity: the degraded read must have actually happened');
        $this->assertStringContainsString('"operation":"read"', $readError);
        $this->assertNull(self::eventContextJsonOf($tree, 'put_skipped'), 'the unrelated, hole-scoped earlier mark must not block this page\'s own fallback template store');

        // Proof it actually stored, not merely "didn't skip this once": stop degrading the
        // read and confirm a later GET is a cache_hit against what the fallback's store
        // (delegated to the real DonutRepository) actually wrote.
        $logger->flush();
        $fakeDonutRepository->failingUri = null;
        $resource->get('page://self/html/blog-posting-donut');
        $laterTree = $this->flushAndValidate($logger);
        $this->assertContains('cache_hit', self::collectTypes($laterTree), 'the fallback\'s store must have actually persisted');
    }

    public function testADegradedReadFallbackThatNowStoresIsStillRefusedByAMarkInsideItsOwnSubtree(): void
    {
        // The other half of the same predicate (reasonExcludingHoleMarks(),
        // isMarkedForCurrentScope()): RenderTimeMark marks its OWN (TOP_LEVEL) scope during
        // its own template render - not inside any hole - the same scope this page's own
        // fallback write checks, so its own write must still be skipped, not stored just
        // because the path got here via a degraded read instead of a normal MISS.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
                $this->bind(DonutRepositoryInterface::class)->to(FakeDonutReadFailsForOneUri::class)->in(Scope::SINGLETON);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $fakeDonutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        assert($fakeDonutRepository instanceof FakeDonutReadFailsForOneUri);
        $fakeDonutRepository->failingUri = 'page://self/html/render-time-mark';

        set_error_handler(static fn (): bool => true); // the degraded-read warning still fires
        try {
            $ro = $resource->get('page://self/html/render-time-mark');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $ro->code);
        $tree = $this->flushAndValidate($logger);
        $readError = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($readError, 'sanity: the degraded read must have actually happened');
        $this->assertStringContainsString('"operation":"read"', $readError);
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped, 'a mark inside this page\'s own write window (its own template render) must still refuse the fallback\'s store');
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);
    }
}
