<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
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

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule((new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace)))));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(RepositoryLoggerInterface::class);

        parent::setUp();
    }

    public function testCommandInterceptorRefresh(): void
    {
        $ro = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayNotHasKey('Age', $ro->headers);
        $ro1 = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $ro1->headers);
        $this->logger->log('delete');
        $this->resource->delete('page://self/html/blog-posting?id=0');
        $this->logger->log('get');
        $log = (string) $this->logger;
        $this->assertStringContainsString('delete
purge-query-repository uri:page://self/html/blog-posting?id=0
delete-etag uri:page://self/html/blog-posting?id=0
delete-donut uri:page://self/html/blog-posting?id=0
no-donut-found uri:page://self/html/blog-posting?id=0
create-donut: uri:page://self/html/blog-posting?id=0 ttl: s-maxage:0
save-donut uri:page://self/html/blog-posting?id=0 s-maxage:
save-view uri:page://self/html/blog-posting?id=0 ttl:0
update-etag uri:page://self/html/blog-posting?id=0 etag:_html_blog-posting_id=0 surrogate-keys:_html_comment_
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
