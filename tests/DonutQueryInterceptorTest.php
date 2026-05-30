<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\Page\Html\BlogPosting;
use FakeVendor\HelloWorld\Resource\Page\Html\Comment;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

class DonutQueryInterceptorTest extends TestCase
{
    use SchemaValidationTrait;

    private ResourceInterface $resource;
    private StructuredRepositoryLoggerInterface $logger;

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
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(RepositoryLoggerInterface::class);
        assert($logger instanceof StructuredRepositoryLoggerInterface);
        $this->logger = $logger;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->assertLogValidatesSchema($this->logger);
    }

    public function testInitialRequest(): string
    {
        // initial request
        $blogPosting = $this->resource->get('page://self/html/blog-posting');
        assert($blogPosting instanceof BlogPosting);
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $view = (string) $blogPosting;
        $this->assertSame('blog-posting:1<comment>comment01</comment>', $view);

        return $blogPosting->headers[Header::SURROGATE_KEY];
    }

    /** @depends testInitialRequest */
    public function testSurrogateKey(string $surrogateKey): void
    {
        // test surrogate key
        $comment = $this->resource->get('page://self/html/comment');
        assert($comment instanceof Comment && isset($comment->headers[Header::ETAG]));
        $commentEtag = $comment->headers[Header::ETAG];
        $this->assertStringContainsString($commentEtag, $surrogateKey);
    }

    /** @depends testInitialRequest */
    public function testCached(): void
    {
        // test cached
        $this->logger->log('request-start', ['uri' => 'page://self/html/blog-posting', 'method' => 'get']);
        $blogPosting = $this->resource->get('page://self/html/blog-posting');
        assert($blogPosting instanceof BlogPosting);
        // Verify key operations structurally via getOps() (order-preserving, not substring matching)
        $ops = $this->logger->getOps();
        foreach (['try-donut-view', 'try-donut', 'cache-miss', 'cache-hit', 'put-donut', 'put-query-repository', 'save-etag', 'save-value', 'save-donut-view', 'save-donut'] as $op) {
            $this->assertContains($op, $ops);
        }

        // The rendered page is served from cache on the second access (layer: donut-view)
        $donutViewHit = false;
        foreach ($this->logger->getLogs() as $log) {
            if ($log['op'] === 'cache-hit' && ($log['layer'] ?? null) === 'donut-view') {
                $donutViewHit = true;
                break;
            }
        }

        $this->assertTrue($donutViewHit, 'expected a cache-hit at the donut-view layer');
        $this->assertArrayHasKey('Age', $blogPosting->headers);
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }

    public function testCreatDonutInResourceObject(): void
    {
        $blogPostingCacheControl = $this->resource->get('page://self/html/blog-posting-cache-control');
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $blogPostingCacheControl->headers);
    }
}
