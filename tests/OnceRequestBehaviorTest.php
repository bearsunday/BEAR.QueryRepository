<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\Counter;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

/**
 * Proves the OnceRequest/EmbedOnceInterceptor design end to end:
 *
 * - an embedded child read in onGet() and rendered as a hole runs exactly once
 * - withQuery() after a read is re-read fresh, through offsetGet() (not a direct call)
 * - a plain explicit resource call, and a #[Cacheable] resource outside a donut page,
 *   keep running every time they always did (the "dice" principle)
 * - issue #226: a child executed only into the body (no hole) tags the stored template;
 *   purging that tag invalidates the page; a never-touched embed neither runs nor tags
 */
class OnceRequestBehaviorTest extends TestCase
{
    use SemanticLogTreeTrait;

    private const COUNTER_DONUT = 'page://self/html/counter-donut';
    private const COUNTER_CACHE_METHOD_LEVEL = 'page://self/html/counter-cache-method-level';
    private const REQUERY_DONUT = 'page://self/html/requery-donut';
    private const BODY_ONLY_CHILD_DONUT = 'page://self/html/body-only-child-donut';
    private const COUNTER = 'app://self/counter';
    private const CHILD = 'page://self/html/comment';
    private const CHILD_URI_TAG = '_html_comment_';
    private const CHILD_SURROGATE_KEY = 'comment01';
    private const COUNTER_URI_TAG = '_app_counter_';
    private const BLOG_POSTING_DONUT = 'page://self/html/blog-posting-donut';
    private const BODY_ONLY_CHILD_CACHEABLE_RESPONSE = 'page://self/html/body-only-child-cacheable-response';

    private ResourceInterface $resource;
    private QueryRepositoryInterface $queryRepository;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        Counter::$calls = 0;
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
    }

    public function testEmbeddedChildExecutesOnceAcrossBodyReadAndHoleRender(): void
    {
        $ro = $this->resource->get(self::COUNTER_DONUT);
        $this->flushAndValidate($this->logger);

        $this->assertSame(1, Counter::$calls, 'read in onGet() and rendered as a hole: one execution total');
        $view = (string) $ro->view;
        $this->assertStringContainsString('read:1', $view);
        $this->assertStringContainsString('call:1', $view, 'the hole was substituted from the very same execution onGet() already read');
    }

    public function testWithQueryAfterAReadReExecutesThroughOffsetGet(): void
    {
        $ro = $this->resource->get(self::REQUERY_DONUT);
        $this->flushAndValidate($this->logger);

        // Both reads went through offsetGet(), never a direct ($request)() call - if
        // OnceRequest::resync() left AbstractRequest's own private $result memo stale,
        // both would read 1 and $calls would stay 1.
        $this->assertSame(2, Counter::$calls, 'withQuery() after a read must force a second, real execution');
        $view = (string) $ro->view;
        $this->assertStringContainsString('first:1', $view);
        $this->assertStringContainsString('second:2', $view);
        $this->assertStringContainsString('secondTag:requeried', $view, 'the second read reflects the new query, not a stale memo of the first');
    }

    public function testEveryGenerationTagsTheStoredTemplateAndPurgingTheFirstOneInvalidatesThePage(): void
    {
        $this->resource->get(self::REQUERY_DONUT);
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::REQUERY_DONUT);
        $warm = $this->flushAndValidate($this->logger);
        $warmClose = self::scopeCloseOf($warm, 'get', self::REQUERY_DONUT);
        $this->assertNotNull($warmClose);
        $this->assertSame('cache_hit', $warmClose[0], 'the stored template is warm before the purge');

        // Only the FIRST generation's tag (the pre-withQuery() counter, no query) - never
        // the second's (tag=requeried). Issue #226 review: OnceRequest discarded its own
        // execution history on resync(), so only the LAST generation's tag ever reached
        // the stored template - purging an earlier generation's tag left the page serving
        // a stale cache_hit.
        $this->queryRepository->purge(new Uri(self::COUNTER));
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::REQUERY_DONUT);
        $afterPurge = $this->flushAndValidate($this->logger);
        $closeAfterPurge = self::scopeCloseOf($afterPurge, 'get', self::REQUERY_DONUT);
        $this->assertNotNull($closeAfterPurge);
        $this->assertSame('cache_miss', $closeAfterPurge[0], "purging the FIRST generation's tag alone must invalidate the page - every generation is tagged, not only the last");
    }

    public function testExplicitResourceCallAlwaysExecutes(): void
    {
        $this->resource->get(self::COUNTER);
        $this->resource->get(self::COUNTER);
        $this->resource->get(self::COUNTER);

        $this->assertSame(3, Counter::$calls, 'a direct, non-embedded call is never wrapped - every call is a real roll of the dice');
    }

    public function testCacheableResourceOutsideDonutPageIsUnaffected(): void
    {
        $this->resource->get(self::CHILD);
        $miss = $this->flushAndValidate($this->logger);
        $missClose = self::scopeCloseOf($miss, 'get', self::CHILD);
        $this->assertNotNull($missClose);
        $this->assertSame('cache_miss', $missClose[0]);

        $this->resource->get(self::CHILD);
        $hit = $this->flushAndValidate($this->logger);
        $hitClose = self::scopeCloseOf($hit, 'get', self::CHILD);
        $this->assertNotNull($hitClose);
        $this->assertSame('cache_hit', $hitClose[0], '#[Cacheable] outside any donut page caches exactly as before - untouched by EmbedOnceInterceptor');
    }

    /**
     * The doPutStatic()/#[CacheableResponse] counterpart of
     * testPurgingTheBodyOnlyChildInvalidatesThePage(): that test proves the #[DonutCache]
     * TEMPLATE is tagged; this one proves the live response's own stored VIEW/ETag - saved
     * through a separate SurrogateKeys instance on this path (issue #226 review) - is too.
     */
    public function testPurgingTheBodyOnlyChildInvalidatesTheCacheableResponseViewToo(): void
    {
        $this->resource->get(self::BODY_ONLY_CHILD_CACHEABLE_RESPONSE);
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::BODY_ONLY_CHILD_CACHEABLE_RESPONSE);
        $warm = $this->flushAndValidate($this->logger);
        $warmClose = self::scopeCloseOf($warm, 'get', self::BODY_ONLY_CHILD_CACHEABLE_RESPONSE);
        $this->assertNotNull($warmClose);
        $this->assertSame('cache_hit', $warmClose[0], 'the stored view/ETag is warm before the purge');

        $this->queryRepository->purge(new Uri(self::CHILD));
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::BODY_ONLY_CHILD_CACHEABLE_RESPONSE);
        $tree = $this->flushAndValidate($this->logger);
        $close = self::scopeCloseOf($tree, 'get', self::BODY_ONLY_CHILD_CACHEABLE_RESPONSE);
        $this->assertNotNull($close);
        $this->assertSame('cache_miss', $close[0], "purging the body-only child's tag must invalidate the live response's own stored view/ETag too, not only a #[DonutCache] template");
    }

    public function testBodyOnlyChildTagsTheStoredTemplateAndNeverTouchedEmbedDoesNot(): void
    {
        $this->resource->get(self::BODY_ONLY_CHILD_DONUT);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame(0, Counter::$calls, "the 'unused' embed is never read and never rendered - it must never run just to be tagged");

        $saveDonut = self::eventContextsJsonOf($tree, 'save_donut', self::BODY_ONLY_CHILD_DONUT);
        $this->assertCount(1, $saveDonut);
        $this->assertStringContainsString('"' . self::CHILD_URI_TAG . '"', $saveDonut[0], "the body-only child's own URI tag lands on the stored template (issue #226)");
        $this->assertStringContainsString('"' . self::CHILD_SURROGATE_KEY . '"', $saveDonut[0], "the body-only child's own declared Surrogate-Key lands on the stored template too");
        $this->assertStringNotContainsString('"' . self::COUNTER_URI_TAG . '"', $saveDonut[0], 'the never-touched embed contributes no tag');
    }

    /**
     * The #[DonutCache] path's own live-response counterpart: no server-side ETag/view is
     * ever saved for this path (doPutDonut() never calls saveEtag()/saveView()), but the
     * CDN-facing Surrogate-Key header on the response itself (issue #226 review) must still
     * carry the body-only child's tag, or a CDN-level purge by that tag cannot reach it.
     */
    public function testDonutCachePathAlsoPutsTheBodyOnlyChildTagOnTheLiveSurrogateKeyHeader(): void
    {
        $ro = $this->resource->get(self::BODY_ONLY_CHILD_DONUT);
        $this->flushAndValidate($this->logger);

        $surrogateKey = $ro->headers[Header::SURROGATE_KEY] ?? '';
        $this->assertStringContainsString(self::CHILD_URI_TAG, $surrogateKey, "the live #[DonutCache] response's own Surrogate-Key header must carry the body-only child's tag too");
    }

    public function testPurgingTheBodyOnlyChildInvalidatesThePage(): void
    {
        $this->resource->get(self::BODY_ONLY_CHILD_DONUT);
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::BODY_ONLY_CHILD_DONUT);
        $warm = $this->flushAndValidate($this->logger);
        $warmClose = self::scopeCloseOf($warm, 'get', self::BODY_ONLY_CHILD_DONUT);
        $this->assertNotNull($warmClose);
        $this->assertSame('cache_hit', $warmClose[0], 'the stored template is warm before the purge');

        $this->queryRepository->purge(new Uri(self::CHILD));
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::BODY_ONLY_CHILD_DONUT);
        $tree = $this->flushAndValidate($this->logger);
        $close = self::scopeCloseOf($tree, 'get', self::BODY_ONLY_CHILD_DONUT);
        $this->assertNotNull($close);
        $this->assertSame('cache_miss', $close[0], "purging the body-only child's tag must invalidate the page's own stored template, not just leave it to a silent refresh");
    }

    public function testMissAndHitServeByteIdenticalViewForAnIdempotentChild(): void
    {
        $missRo = $this->resource->get(self::BLOG_POSTING_DONUT);
        $this->flushAndValidate($this->logger);
        $missView = (string) $missRo->view;
        $this->assertStringContainsString('comment01', $missView);

        $hitRo = $this->resource->get(self::BLOG_POSTING_DONUT);
        $hit = $this->flushAndValidate($this->logger);
        $hitClose = self::scopeCloseOf($hit, 'get', self::BLOG_POSTING_DONUT);
        $this->assertNotNull($hitClose);
        $this->assertSame('cache_hit', $hitClose[0], 'this read must come from the stored donut template, not a fresh render');

        $this->assertSame(
            $missView,
            (string) $hitRo->view,
            'the MISS render and the HIT-refresh of an idempotent child must be byte-identical - the whole point of "MISS equals the render without #[DonutCache], HIT re-fetches only holes"',
        );
    }

    public function testMethodLevelCacheableResponseEmbedExecutesOnceToo(): void
    {
        // DonutCacheModule::installAopMethodModule() binds EmbedOnceInterceptor a second
        // time, for a class whose #[CacheableResponse] sits on onGet() itself (like
        // BlogPostingCache) rather than on the class - this is the fixture proving THAT
        // binding is actually reached, not merely registered at configure() time: a dead
        // binding here would leave 'counter' as a plain Request, double-executing it the
        // same way CounterDonut (class-level) would without EmbedOnceInterceptor at all.
        $ro = $this->resource->get(self::COUNTER_CACHE_METHOD_LEVEL);
        $this->flushAndValidate($this->logger);

        $this->assertSame(1, Counter::$calls);
        $view = (string) $ro->view;
        $this->assertStringContainsString('read:1', $view);
        $this->assertStringContainsString('call:1', $view);
    }
}
