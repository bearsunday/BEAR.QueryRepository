<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

class DonutCommandInterceptorTest extends TestCase
{
    /** @var ResourceInterface */
    private $resource;

    /** @var RepositoryLoggerInterface */
    private $logger;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace)));
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
purge-query uri:page://self/html/blog-posting?id=0
delete-etag uri:page://self/html/blog-posting?id=0
delete-donut uri:page://self/html/blog-posting?id=0
no-donut-found uri:page://self/html/blog-posting?id=0
create-donut: uri:page://self/html/blog-posting?id=0 s-maxage:0 donut-age:
save-donut uri:page://self/html/blog-posting?id=0 s-maxage:
delete-etag uri:page://self/html/blog-posting?id=0
save-etag: uri:page://self/html/blog-posting?id=0 ttl:
save-donut-view uri:page://self/html/blog-posting?id=0 ttl:
save-etag: uri:page://self/html/blog-posting?id=0 ttl:
get', $log);
        $ro = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $ro->headers);
    }
}
