<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Html\BlogPostingDonut;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

class DonutCacheInterceptorTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;
    private Injector $injector;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule((new FakeEtagPoolModule(ModuleFactory::getInstance($namespace))));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof Injector);
        $this->injector = $injector;
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted entry must conform to its context schema (drift detection)
        $this->flushAndValidate($this->logger);
    }

    public function testInitialRequest(): string
    {
        // initial request
        $blogPosting = $this->resource->get('page://self/html/blog-posting-donut');
        assert($blogPosting instanceof BlogPostingDonut);
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $view = (string) $blogPosting;
        $this->assertSame('blog-posting:1<comment>comment01</comment>', $view);

        // save_donut records the tags the template entry is stored under: its own URI tag (what
        // purge($uri) invalidates), plus any declared Surrogate-Key.
        $tree = $this->flushAndValidate($this->logger);
        $saveDonut = self::eventContextJsonOf($tree, 'save_donut');
        $this->assertNotNull($saveDonut);
        $this->assertStringContainsString('"tags":["_html_blog-posting-donut_"]', $saveDonut);

        return $blogPosting->headers[Header::SURROGATE_KEY];
    }

    /** @depends testInitialRequest */
    public function testCached(): void
    {
        $this->logger->flush(); // drain the initial-request session

        $blogPosting = $this->resource->get('page://self/html/blog-posting-donut');
        assert($blogPosting instanceof BlogPostingDonut);

        // The whole tree validates, and the cached access reuses the donut structure
        // (cache_hit) then rebuilds the view (refresh_donut) rather than a full miss.
        $tree = $this->flushAndValidate($this->logger);
        $types = self::collectTypes($tree);
        $this->assertContains('get', $types);
        $this->assertContains('cache_hit', $types);
        $this->assertContains('refresh_donut', $types);

        // The page is not entire-content cacheable (putDonut): the refreshed view is
        // served live and no page-level save follows — recorded as put_skipped.
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped, 'the missing page-level save after refresh is explained');
        $this->assertStringContainsString('"reason":"not-cacheable"', $putSkipped);

        // The same fact CDN-side: the response carries purge keys but no lifetime header,
        // so the CDN serves this page live. Before cdn_headers the log could not tell
        // this apart from a page the CDN caches for the setter's default.
        $cdnHeaders = self::eventContextJsonOf($tree, 'cdn_headers');
        $this->assertNotNull($cdnHeaders, 'the refresh records what the CDN was told');
        $this->assertStringNotContainsString('Cache-Control', $cdnHeaders, 'no lifetime header: the CDN must not cache the page');
        $this->assertStringContainsString('"Surrogate-Key"', $cdnHeaders);

        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }

    /** @depends testCached */
    public function testPurgingThePageEvictsItsDonutTemplate(): void
    {
        $this->logger->flush();
        $uri = new Uri('page://self/html/blog-posting-donut');
        $repository = $this->injector->getInstance(QueryRepositoryInterface::class);

        // This page declares no Surrogate-Key of its own: before the fix its template entry
        // carried no tags, so this purge left an immortal shell (issue #185).
        $this->assertTrue($repository->purge($uri));
        $this->logger->flush();

        $this->resource->get('page://self/html/blog-posting-donut');
        $tree = $this->flushAndValidate($this->logger);
        $types = self::collectTypes($tree);

        $this->assertNotContains('refresh_donut', $types, 'the purged template is gone, so nothing is recomposed from it');
        $this->assertContains('put_donut', $types, 'the page is built again and stored again');
    }
}
