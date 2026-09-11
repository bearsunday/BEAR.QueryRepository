<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function array_filter;
use function array_values;
use function dirname;
use function str_contains;

/**
 * Which entry carries a child's tags is decided by the parent's cache declaration
 *
 * The `#[Cacheable]` evidence - a `depends_on` edge, and a parent miss once the child is
 * purged - is the shape of one declaration out of three, not the shape of donut caching.
 */
class DonutDependencyEvidenceTest extends TestCase
{
    use SemanticLogTreeTrait;

    private const CACHEABLE = 'page://self/dep/level-one';
    private const CACHEABLE_CHILD = 'page://self/dep/level-two';
    private const CACHEABLE_RESPONSE = 'page://self/html/blog-posting';
    private const DONUT_CACHE = 'page://self/html/blog-posting-donut';
    private const CHILD = 'page://self/html/comment';
    private const CHILD_URI_TAG = '_html_comment_';
    private const CHILD_SURROGATE_KEY = 'comment01';

    private ResourceInterface $resource;
    private QueryRepositoryInterface $queryRepository;
    private SemanticLoggerInterface $logger;

    /** The html pages render through Twig; the dep/* chain has no template and takes the default renderer */
    private function bootDonutApp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $this->boot($module);
    }

    private function bootCacheableApp(): void
    {
        $this->boot(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')));
    }

    private function boot(AbstractModule $module): void
    {
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
    }

    public function testCacheableParentRecordsADependsOnEdge(): void
    {
        $this->bootCacheableApp();
        $this->resource->get(self::CACHEABLE);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertNotSame([], self::dependsOnEdgesFrom($tree, self::CACHEABLE), '#[Cacheable] merges the child tags through CacheDependency, which is what emits the edge');
    }

    public function testCacheableParentMissesAfterItsChildIsPurged(): void
    {
        $this->bootCacheableApp();
        $this->resource->get(self::CACHEABLE);
        $this->flushAndValidate($this->logger);
        $this->queryRepository->purge(new Uri(self::CACHEABLE_CHILD));
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::CACHEABLE);
        $tree = $this->flushAndValidate($this->logger);

        $close = self::scopeCloseOf($tree, 'get', self::CACHEABLE);
        $this->assertNotNull($close);
        $this->assertSame('cache_miss', $close[0], "the child's tag was on the parent entry, so the purge took the parent with it");
        $this->assertStringContainsString('"layer":"resource"', $close[1]);
    }

    public function testCacheableResponseWriteRecordsNoDependsOnEdge(): void
    {
        $this->bootDonutApp();
        $this->resource->get(self::CACHEABLE_RESPONSE);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame([], self::dependsOnEdgesFrom($tree, self::CACHEABLE_RESPONSE), 'a #[CacheableResponse] write never reaches CacheDependency');
    }

    public function testCacheableResponseTagsEtagAndViewWithTheChildButNotTheTemplate(): void
    {
        $this->bootDonutApp();
        $this->resource->get(self::CACHEABLE_RESPONSE);
        $tree = $this->flushAndValidate($this->logger);

        $saveEtag = self::eventContextsJsonOf($tree, 'save_etag', self::CACHEABLE_RESPONSE);
        $this->assertCount(1, $saveEtag);
        $this->assertStringContainsString('"' . self::CHILD_URI_TAG . '"', $saveEtag[0], "the child's URI tag reaches the validator");
        $this->assertStringContainsString('"' . self::CHILD_SURROGATE_KEY . '"', $saveEtag[0], "the child's declared Surrogate-Key reaches the validator");

        $saveDonutView = self::eventContextsJsonOf($tree, 'save_donut_view', self::CACHEABLE_RESPONSE);
        $this->assertCount(1, $saveDonutView);
        $this->assertStringContainsString('"' . self::CHILD_URI_TAG . '"', $saveDonutView[0], "the child's URI tag reaches the page view");
        $this->assertStringContainsString('"' . self::CHILD_SURROGATE_KEY . '"', $saveDonutView[0], "the child's declared Surrogate-Key reaches the page view");

        $saveDonut = self::eventContextsJsonOf($tree, 'save_donut', self::CACHEABLE_RESPONSE);
        $this->assertCount(1, $saveDonut);
        $this->assertStringNotContainsString('"' . self::CHILD_URI_TAG . '"', $saveDonut[0], 'the template outlives the child so the shell can be recomposed');
        $this->assertStringNotContainsString('"' . self::CHILD_SURROGATE_KEY . '"', $saveDonut[0], 'the template outlives the child so the shell can be recomposed');
    }

    public function testCacheableResponseRebuildsFromItsTemplateAfterTheChildIsPurged(): void
    {
        $this->bootDonutApp();
        $this->resource->get(self::CACHEABLE_RESPONSE);
        $this->flushAndValidate($this->logger);
        $this->queryRepository->purge(new Uri(self::CHILD));
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::CACHEABLE_RESPONSE);
        $tree = $this->flushAndValidate($this->logger);

        $close = self::scopeCloseOf($tree, 'get', self::CACHEABLE_RESPONSE);
        $this->assertNotNull($close);
        $this->assertSame('cache_hit', $close[0], 'the page came out of the donut layer, not out of a resource run');
        $this->assertStringContainsString('"layer":"donut-view"', $close[1]);
        $this->assertNotSame([], self::eventContextsJsonOf($tree, 'refresh_donut', self::CACHEABLE_RESPONSE), 'a hit without this event is the stale shape');
        $this->assertNotSame([], self::eventContextsJsonOf($tree, 'save_donut_view', self::CACHEABLE_RESPONSE), 'the recomposed page is stored again');
        $this->assertSame([], self::dependsOnEdgesFrom($tree, self::CACHEABLE_RESPONSE), 'recomposition records no dependency edge either');

        $childClose = self::scopeCloseOf($tree, 'get', self::CHILD);
        $this->assertNotNull($childClose);
        $this->assertSame('cache_miss', $childClose[0], 'the purge landed on the child, which is where the freshness came from');
    }

    public function testDonutCacheKeepsTheChildTagsOutOfTheStore(): void
    {
        $this->bootDonutApp();
        $this->resource->get(self::DONUT_CACHE);
        $tree = $this->flushAndValidate($this->logger);

        $saveDonut = self::eventContextsJsonOf($tree, 'save_donut', self::DONUT_CACHE);
        $this->assertCount(1, $saveDonut);
        $this->assertStringNotContainsString('"' . self::CHILD_URI_TAG . '"', $saveDonut[0], 'the template is the only entry, and it is not tagged by its children');
        $this->assertSame([], self::eventContextsJsonOf($tree, 'save_etag', self::DONUT_CACHE), 'no validator is stored for a page that is never stored');
        $this->assertSame([], self::eventContextsJsonOf($tree, 'save_donut_view', self::DONUT_CACHE), 'no page view is stored');
        $this->assertSame([], self::dependsOnEdgesFrom($tree, self::DONUT_CACHE), 'a #[DonutCache] write never reaches CacheDependency');

        $cdnHeaders = self::eventContextsJsonOf($tree, 'cdn_headers', self::DONUT_CACHE);
        $this->assertNotSame([], $cdnHeaders);
        $this->assertStringContainsString('"' . self::CHILD_URI_TAG . '"', $cdnHeaders[0], "the child's tag travels to the edge, the one place this parent records it");
    }

    public function testDonutCacheReadShapeIsUnchangedByPurgingTheChild(): void
    {
        $this->bootDonutApp();
        $this->resource->get(self::DONUT_CACHE);
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::DONUT_CACHE);
        $warm = $this->flushAndValidate($this->logger);
        $warmTypes = self::scopeEventTypesOf($warm, 'get', self::DONUT_CACHE);
        $this->assertSame(['cache_hit', 'refresh_donut', 'cdn_headers', 'put_skipped'], $warmTypes);

        $this->queryRepository->purge(new Uri(self::CHILD));
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::DONUT_CACHE);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame($warmTypes, self::scopeEventTypesOf($tree, 'get', self::DONUT_CACHE), 'the parent never recorded the dependency, so purging the child cannot change its shape');
        $close = self::scopeCloseOf($tree, 'get', self::DONUT_CACHE);
        $this->assertNotNull($close);
        $this->assertSame('cache_hit', $close[0]);
        $this->assertStringContainsString('"layer":"donut-view"', $close[1]);
        $this->assertStringContainsString('"reason":"not-cacheable"', self::eventContextsJsonOf($tree, 'put_skipped', self::DONUT_CACHE)[0]);

        $childClose = self::scopeCloseOf($tree, 'get', self::CHILD);
        $this->assertNotNull($childClose);
        $this->assertSame('cache_miss', $childClose[0], 'the only thing the purge changed is the embedded child');
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function dependsOnEdgesFrom(array $tree, string $parent): array
    {
        $edges = self::eventContextsJsonOf($tree, 'depends_on');

        return array_values(array_filter($edges, static fn (string $json): bool => str_contains($json, '"parent":"' . $parent . '"')));
    }
}
