<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Counter;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

/**
 * #226: an embedded child read in onGet() and rendered as a hole runs once
 */
class EmbeddedChildExecutesOnceTest extends TestCase
{
    use SemanticLogTreeTrait;

    private const COUNTER_DONUT = 'page://self/html/counter-donut';
    private const COUNTER_CACHE_CLASS_LEVEL = 'page://self/html/counter-cache';
    private const COUNTER_CACHE_METHOD_LEVEL = 'page://self/html/counter-cache-method-level';
    private const COUNTER = 'app://self/counter';
    private const CHILD = 'page://self/html/comment';
    private const BLOG_POSTING_DONUT = 'page://self/html/blog-posting-donut';

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        Counter::$calls = 0;
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
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

    public function testClassLevelCacheableResponseEmbedExecutesOnceToo(): void
    {
        $ro = $this->resource->get(self::COUNTER_CACHE_CLASS_LEVEL);
        $this->flushAndValidate($this->logger);

        $this->assertSame(1, Counter::$calls, 'read in onGet() and rendered as a hole: one execution total');
        $view = (string) $ro->view;
        $this->assertStringContainsString('read:1', $view);
        $this->assertStringContainsString('call:1', $view, 'the hole was substituted from the very same execution onGet() already read');
    }

    public function testMethodLevelCacheableResponseEmbedExecutesOnceToo(): void
    {
        $ro = $this->resource->get(self::COUNTER_CACHE_METHOD_LEVEL);
        $this->flushAndValidate($this->logger);

        $this->assertSame(1, Counter::$calls, 'read in onGet() and rendered as a hole: one execution total');
        $view = (string) $ro->view;
        $this->assertStringContainsString('read:1', $view);
        $this->assertStringContainsString('call:1', $view, 'the hole was substituted from the very same execution onGet() already read');
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
        $this->assertSame('cache_hit', $hitClose[0], '#[Cacheable] outside any donut page caches exactly as before');
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
}
