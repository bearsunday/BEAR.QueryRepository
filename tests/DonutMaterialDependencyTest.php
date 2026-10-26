<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Html\MutableComment;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_filter;
use function array_values;
use function dirname;
use function str_contains;

/**
 * A #[DonutCache] page's embedded child is tagged as a dependency ("material") when the
 * page's own onGet() already read it - as opposed to a pure hole, which the renderer runs
 * later and whose tag never reaches the template (DonutDependencyEvidenceTest covers the
 * unaffected pure-hole case).
 */
class DonutMaterialDependencyTest extends TestCase
{
    use SemanticLogTreeTrait;

    private const PAGE_WITH_CACHEABLE_MATERIAL = 'page://self/html/donut-cache-with-material';
    private const CACHEABLE_MATERIAL_CHILD = 'page://self/html/comment';
    private const CACHEABLE_MATERIAL_CHILD_URI_TAG = '_html_comment_';
    private const CACHEABLE_MATERIAL_CHILD_SURROGATE_KEY = 'comment01';

    private const PAGE_WITH_DONUT_MATERIAL = 'page://self/html/donut-cache-with-donut-child-material';
    private const DONUT_MATERIAL_CHILD = 'page://self/html/donut-material-child';
    private const DONUT_MATERIAL_CHILD_URI_TAG = '_html_donut-material-child_';

    private const PAGE_WITH_TAGGED_HEADER_HOLE = 'page://self/html/donut-cache-with-tagged-header-hole';
    private const TAGGED_HEADER_CHILD = 'page://self/html/tagged-header-default-child';
    private const TAGGED_HEADER_CHILD_URI_TAG = '_html_tagged-header-default-child_';
    private const TAGGED_HEADER_CHILD_SURROGATE_KEY = 'tagged-header-default-child';

    private const PAGE_WITH_MUTABLE_HOLE = 'page://self/html/donut-cache-with-mutable-hole';
    private const MUTABLE_CHILD = 'page://self/html/mutable-comment';

    private const PAGE_WITH_MUTABLE_MATERIAL = 'page://self/html/donut-cache-with-mutable-material';

    private const PAGE_RESPONSE_WITH_MUTABLE_MATERIAL = 'page://self/html/cacheable-response-with-material';

    private ResourceInterface $resource;
    private QueryRepositoryInterface $queryRepository;
    private ResourceStorageInterface $storage;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        MutableComment::$comment = 'comment-a';
        MutableComment::$markUnstorable = false;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        MutableComment::$comment = 'comment-a';
        MutableComment::$markUnstorable = false;

        parent::tearDown();
    }

    public function testMaterialChildTagsReachTheStoredTemplateAndADependsOnEdgeIsLogged(): void
    {
        $this->resource->get(self::PAGE_WITH_CACHEABLE_MATERIAL);
        $tree = $this->flushAndValidate($this->logger);

        $saveDonut = self::eventContextsJsonOf($tree, 'save_donut', self::PAGE_WITH_CACHEABLE_MATERIAL);
        $this->assertCount(1, $saveDonut);
        $this->assertStringContainsString('"' . self::CACHEABLE_MATERIAL_CHILD_URI_TAG . '"', $saveDonut[0], "the child's URI tag reaches the stored template");
        $this->assertStringContainsString('"' . self::CACHEABLE_MATERIAL_CHILD_SURROGATE_KEY . '"', $saveDonut[0], "the child's own Surrogate-Key reaches the stored template");

        $edges = self::dependsOnEdgesFrom($tree, self::PAGE_WITH_CACHEABLE_MATERIAL);
        $this->assertCount(1, $edges, 'reading the embedded child in onGet() makes it a material, so depends() runs for it');
        $this->assertStringContainsString('"child":"' . self::CACHEABLE_MATERIAL_CHILD . '"', $edges[0]);
    }

    public function testPurgingTheMaterialChildMakesTheNextGetAFreshMiss(): void
    {
        $this->resource->get(self::PAGE_WITH_CACHEABLE_MATERIAL);
        $this->flushAndValidate($this->logger);

        $purged = $this->queryRepository->purge(new Uri(self::CACHEABLE_MATERIAL_CHILD));
        $this->assertTrue($purged);
        $this->flushAndValidate($this->logger);

        $this->resource->get(self::PAGE_WITH_CACHEABLE_MATERIAL);
        $tree = $this->flushAndValidate($this->logger);

        $close = self::scopeCloseOf($tree, 'get', self::PAGE_WITH_CACHEABLE_MATERIAL);
        $this->assertNotNull($close);
        $this->assertSame('cache_miss', $close[0], "the child's own tag was on the template, so the purge took the template with it");
        $this->assertStringContainsString('"layer":"donut-view"', $close[1]);
        $this->assertNotSame([], self::eventContextsJsonOf($tree, 'put_donut', self::PAGE_WITH_CACHEABLE_MATERIAL), 'a fresh miss re-runs onGet() and re-composes the template, not just a hole-only refresh');
        $this->assertNotSame([], self::dependsOnEdgesFrom($tree, self::PAGE_WITH_CACHEABLE_MATERIAL), 'the re-composition reads the child again, so it is re-classified as a material too');
    }

    /**
     * The log-shape proof above (cache_miss + put_donut + depends_on) does not by itself
     * show the served content actually changed - a constant-valued child could pass it
     * vacuously. This drives a mutable material end to end: the page's own view must
     * carry the child's new value after the purge, not the one baked into the old
     * template.
     */
    public function testPurgingTheMaterialChildServesFreshContentOnTheNextGet(): void
    {
        MutableComment::$comment = 'comment-a';
        $first = $this->resource->get(self::PAGE_WITH_MUTABLE_MATERIAL);
        $this->assertStringContainsString('comment-a', (string) $first->view);
        $this->flushAndValidate($this->logger);

        MutableComment::$comment = 'comment-b';
        $purged = $this->queryRepository->purge(new Uri(self::MUTABLE_CHILD));
        $this->assertTrue($purged);
        $this->flushAndValidate($this->logger);

        $second = $this->resource->get(self::PAGE_WITH_MUTABLE_MATERIAL);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertStringContainsString('comment-b', (string) $second->view, 'the purge reached the material-tagged template, so the recomposed page carries the new value');
        $this->assertStringNotContainsString('comment-a', (string) $second->view);

        $close = self::scopeCloseOf($tree, 'get', self::PAGE_WITH_MUTABLE_MATERIAL);
        $this->assertNotNull($close);
        $this->assertSame('cache_miss', $close[0]);
        $this->assertNotSame([], self::dependsOnEdgesFrom($tree, self::PAGE_WITH_MUTABLE_MATERIAL));
    }

    /**
     * `#[CacheableResponse]` material classification mirrors `#[DonutCache]`: the child
     * is both read in onGet() (a material) and rendered as the template's own hole.
     */
    public function testCacheableResponseMaterialTagsBothTheTemplateAndTheResponse(): void
    {
        $this->resource->get(self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL);
        $tree = $this->flushAndValidate($this->logger);

        $childUriTag = (new UriTag())(new Uri(self::MUTABLE_CHILD));
        $saveDonut = self::eventContextsJsonOf($tree, 'save_donut', self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL);
        $this->assertCount(1, $saveDonut);
        $this->assertStringContainsString('"' . $childUriTag . '"', $saveDonut[0], "the child's URI tag reaches the stored template");

        $saveEtag = self::eventContextsJsonOf($tree, 'save_etag', self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL);
        $this->assertCount(1, $saveEtag);
        $this->assertStringContainsString('"' . $childUriTag . '"', $saveEtag[0], "the child's URI tag reaches the response's own validator too");

        $this->assertNotSame([], self::dependsOnEdgesFrom($tree, self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL));
    }

    public function testCacheableResponsePurgingTheMaterialChildServesFreshContentOnTheNextGet(): void
    {
        MutableComment::$comment = 'comment-a';
        $first = $this->resource->get(self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL);
        $this->assertStringContainsString('comment-a', (string) $first->view);
        $this->flushAndValidate($this->logger);

        MutableComment::$comment = 'comment-b';
        $purged = $this->queryRepository->purge(new Uri(self::MUTABLE_CHILD));
        $this->assertTrue($purged);
        $this->flushAndValidate($this->logger);

        $second = $this->resource->get(self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertStringContainsString('comment-b', (string) $second->view, "the material tag reached the template, so purging the child drops it and the next GET rebuilds with the child's fresh value");
        $this->assertStringNotContainsString('comment-a', (string) $second->view);

        $close = self::scopeCloseOf($tree, 'get', self::PAGE_RESPONSE_WITH_MUTABLE_MATERIAL);
        $this->assertNotNull($close);
        $this->assertSame('cache_miss', $close[0]);
    }

    public function testADonutCacheChildIsClassifiedThroughItsOwnSurrogateKeyNotAnEtag(): void
    {
        $this->resource->get(self::PAGE_WITH_DONUT_MATERIAL);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame([], self::eventContextsJsonOf($tree, 'save_etag', self::DONUT_MATERIAL_CHILD), 'a #[DonutCache] child never gets an ETag - the only signal available is its Surrogate-Key');

        $edges = self::dependsOnEdgesFrom($tree, self::PAGE_WITH_DONUT_MATERIAL);
        $this->assertCount(1, $edges, 'the child was read in onGet(), so it is still classified as a material through its Surrogate-Key');
        $this->assertStringContainsString('"child":"' . self::DONUT_MATERIAL_CHILD . '"', $edges[0]);

        $saveDonut = self::eventContextsJsonOf($tree, 'save_donut', self::PAGE_WITH_DONUT_MATERIAL);
        $this->assertCount(1, $saveDonut);
        $this->assertStringContainsString('"' . self::DONUT_MATERIAL_CHILD_URI_TAG . '"', $saveDonut[0], "the child's URI tag reaches the stored template");
    }

    /**
     * A child class that declares Surrogate-Key as a $headers property default already
     * carries that header before onGet() ever runs, so a parent that only embeds it as a
     * hole (never reading it) still misclassifies it as a material (documented known
     * limit). The regression this pins is narrower: with the unset removed from
     * CacheDependency::depends(), the child's own write must keep its own key - losing it
     * would strip the key from both the child's independent cache entry and its own
     * save_etag record.
     */
    public function testAChildTaggedThroughAHeaderDefaultKeepsItsOwnKeyAfterBeingMisreadAsAMaterial(): void
    {
        $this->resource->get(self::PAGE_WITH_TAGGED_HEADER_HOLE);
        $tree = $this->flushAndValidate($this->logger);

        $edges = self::dependsOnEdgesFrom($tree, self::PAGE_WITH_TAGGED_HEADER_HOLE);
        $this->assertCount(1, $edges, 'the property-default header makes this pure hole look like a material');

        $saveEtag = self::eventContextsJsonOf($tree, 'save_etag', self::TAGGED_HEADER_CHILD);
        $this->assertCount(1, $saveEtag);
        $this->assertStringContainsString('"' . self::TAGGED_HEADER_CHILD_SURROGATE_KEY . '"', $saveEtag[0], "the child's own key must survive depends() to reach its own save_etag");
        $this->assertStringContainsString('"' . self::TAGGED_HEADER_CHILD_URI_TAG . '"', $saveEtag[0]);

        $direct = $this->resource->get(self::TAGGED_HEADER_CHILD);
        $this->assertSame(self::TAGGED_HEADER_CHILD_SURROGATE_KEY, $direct->headers[Header::SURROGATE_KEY] ?? null, "the child's own response keeps its own key too");
    }

    /**
     * Pins DonutRenderer: a second full MISS of the same page in one long-running
     * container must render the hole's FRESH content, not the first MISS's stale view
     * (setView() must overwrite by placeholder, not append).
     */
    public function testASecondFullMissOfTheSamePageRendersTheFreshHoleContent(): void
    {
        $first = $this->resource->get(self::PAGE_WITH_MUTABLE_HOLE);
        $this->assertStringContainsString('comment-a', (string) $first->view);
        $this->flushAndValidate($this->logger);

        MutableComment::$comment = 'comment-b';
        // Purging only the page's own tag would leave the template hit and fall into
        // refreshDonut() (a different, unaffected code path) - the template AND the hole's
        // own entry both have to go, forcing doPutDonut() to run create()/render() again.
        $this->storage->invalidateTags([
            (new UriTag())(new Uri(self::PAGE_WITH_MUTABLE_HOLE)),
            (new UriTag())(new Uri(self::MUTABLE_CHILD)),
        ]);
        $this->flushAndValidate($this->logger);

        $second = $this->resource->get(self::PAGE_WITH_MUTABLE_HOLE);
        $this->flushAndValidate($this->logger);

        $this->assertStringContainsString('comment-b', (string) $second->view);
        $this->assertStringNotContainsString('comment-a', (string) $second->view, 'the second MISS must not replay the first MISS\'s stale hole view');
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
