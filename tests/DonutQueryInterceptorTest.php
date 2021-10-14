<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
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
    /** @var ResourceInterface */
    private $resource;

    /** @var RepositoryLoggerInterface */
    private $logger;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule((new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace)))));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, $_ENV['TMP_DIR']);
        }

        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(RepositoryLoggerInterface::class);
        parent::setUp();
    }

    public function testInitialRequest(): string
    {
        // initial request
        $blogPosting = $this->resource->get('page://self/html/blog-posting');
        assert($blogPosting instanceof BlogPosting);
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $view = (string) $blogPosting;
        $this->assertSame('blog-posting:1<comment>comment01</comment>', $view);

        return $blogPosting->headers[Header::PURGE_KEYS];
    }

    /**
     * @depends testInitialRequest
     */
    public function testSurrogateKey(string $surrogateKey): void
    {
        // test surrogate key
        $comment = $this->resource->get('page://self/html/comment');
        assert($comment instanceof Comment && isset($comment->headers[Header::ETAG]));
        $commentEtag = $comment->headers[Header::ETAG];
        $this->assertSame($surrogateKey, $commentEtag);
    }

    /**
     * @depends testInitialRequest
     */
    public function testCached(): void
    {
        // test cached
        $this->logger->log('get');
        $blogPosting = $this->resource->get('page://self/html/blog-posting');
        assert($blogPosting instanceof BlogPosting);
        $log = (string) $this->logger;
        $this->assertSame('no-donut-found uri:page://self/html/blog-posting
create-donut: uri:page://self/html/blog-posting ttl: s-maxage:0
put-query-repository uri:page://self/html/comment
update-etag uri:page://self/html/comment etag:_html_comment_ surrogate-keys:
save-value uri:page://self/html/comment ttl:31536000
save-donut uri:page://self/html/blog-posting s-maxage:
save-view uri:page://self/html/blog-posting ttl:0
update-etag uri:page://self/html/blog-posting etag:_html_blog-posting_ surrogate-keys:_html_comment_
get
get-donut-cache: uri:page://self/html/blog-posting', $log);
        $this->assertArrayHasKey('Age', $blogPosting->headers);
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }

    public function testCreatDonutInResourceObject(): void
    {
        $blogPostingCacheControl = $this->resource->get('page://self/html/blog-posting-cache-control');
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $blogPostingCacheControl->headers);
    }
}
