<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface as HttpCacheInterfaceAlias;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_key_exists;
use function array_map;
use function assert;
use function dirname;
use function get_class;
use function property_exists;

class DonutCommandInterceptorTest extends TestCase
{
    /** @var ResourceInterface */
    private $resource;

    /** @var RepositoryLoggerInterface */
    private $logger;

    /** @var HttpCacheInterfaceAlias */
    private $httpCache;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule((new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace)))));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(RepositoryLoggerInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterfaceAlias::class);

        parent::setUp();
    }

    public function testCommandInterceptorRefresh(): void
    {
        $ro = $this->resource->get('page://self/html/blog-posting?id=0');
        $etag = $ro->headers[Header::ETAG];
        $this->assertArrayNotHasKey('Age', $ro->headers);
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $etag,
        ];
        $this->assertTrue($this->httpCache->isNotModified($server));
        $ro1 = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $ro1->headers);
        $this->logger->log('delete');
        $this->resource->delete('page://self/html/blog-posting?id=0');
        $this->assertTrue($this->httpCache->isNotModified($server));
        $this->logger->log('get');
        $log = (string) $this->logger;
        $this->assertStringContainsString('delete
purge-query-repository uri:page://self/html/blog-posting?id=0
invalidate-etag tags:_html_blog-posting_id=0
refresh-donut: uri:page://self/html/blog-posting?id=0
invalidate-etag tags:_html_blog-posting_id=0
save-etag uri:page://self/html/blog-posting?id=0 etag:_html_blog-posting_id=0r surrogate-keys:_html_blog-posting_id=0 _html_comment_
save-view uri:page://self/html/blog-posting?id=0 surrogate-keys:_html_blog-posting_id=0 _html_comment_ ttl:0
save-donut-view uri:page://self/html/blog-posting?id=0 surrogate-keys:_html_blog-posting_id=0r _html_blog-posting_id=0 _html_comment_ s-maxage:
get', $log);
        $ro = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $ro->headers);
    }

    public function testCacheableResponse(): void
    {
        $ro = $this->resource->get('page://self/html/blog-posting-cache?id=0');
        assert(property_exists($ro, 'bindings'));
        assert(array_key_exists('onGet', $ro->bindings));
        $interceptors = array_map(static function (object $object): string {
            return get_class($object);
        }, $ro->bindings['onGet']);
        $this->assertContains(DonutQueryInterceptor::class, $interceptors);
        assert(isset($ro->bindings['onGet'][0]));
        assert(isset($ro->bindings['onDelete'][0]));
        $this->assertInstanceOf(DonutQueryInterceptor::class, $ro->bindings['onDelete'][0]);
    }
}
