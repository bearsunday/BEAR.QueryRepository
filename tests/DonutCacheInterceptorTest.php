<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\Page\Html\BlogPostingDonut;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

class DonutCacheInterceptorTest extends TestCase
{
    private ResourceInterface $resource;
    private RepositoryLoggerInterface $logger;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule((new FakeEtagPoolModule(ModuleFactory::getInstance($namespace))));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, $_ENV['TMP_DIR']);
        }

        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(RepositoryLoggerInterface::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $log = ((string) $this->logger);
        // error_log((string) $log);  // uncomment to see the debug log
        unset($log);
    }

    public function testInitialRequest(): string
    {
        // initial request
        $blogPosting = $this->resource->get('page://self/html/blog-posting-donut');
        assert($blogPosting instanceof BlogPostingDonut);
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $view = (string) $blogPosting;
        $this->assertSame('blog-posting:1<comment>comment01</comment>', $view);

        return $blogPosting->headers[Header::SURROGATE_KEY];
    }

    /** @depends testInitialRequest */
    public function testCached(): void
    {
        // test cached
        $this->logger->log('get');
        $blogPosting = $this->resource->get('page://self/html/blog-posting-donut');
        assert($blogPosting instanceof BlogPostingDonut);
        $log = (string) $this->logger;
        $this->assertSame('try-donut-view: uri:page://self/html/blog-posting-donut
try-donut uri:page://self/html/blog-posting-donut
no-donut-found uri:page://self/html/blog-posting-donut
put-donut: uri:page://self/html/blog-posting-donut ttl:
put-query-repository uri:page://self/html/comment
invalidate-etag tags:_html_comment_
save-etag uri:page://self/html/comment etag:_html_comment_ surrogate-keys:comment01 _html_comment_
save-value uri:page://self/html/comment tags:_html_comment_ comment01 ttl:31536000
invalidate-etag tags:_html_blog-posting-donut_
save-donut uri:page://self/html/blog-posting-donut s-maxage:
get
try-donut-view: uri:page://self/html/blog-posting-donut
try-donut uri:page://self/html/blog-posting-donut
refresh-donut: uri:page://self/html/blog-posting-donut', $log);
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }
}
