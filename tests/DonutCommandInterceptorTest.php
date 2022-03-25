<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Code;
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
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(RepositoryLoggerInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterfaceAlias::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $log = ((string) $this->logger);
        // error_log((string) $log);  // uncomment to see the debug log
        unset($log);
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
        $this->assertFalse($this->httpCache->isNotModified($server));
        $this->logger->log('server:%s', $server);
        $this->logger->log('get');
        $ro = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $ro->headers);
    }

    public function testCommandInterceptorRefreshOnErrorCode(): void
    {
        $this->resource->get('page://self/html/comment');
        $ro = $this->resource->delete('page://self/html/comment');
        $this->assertSame(Code::BAD_REQUEST, $ro->code);
        $ro = $this->resource->get('page://self/html/comment');
        $this->assertArrayHasKey('Age', $ro->headers);

        $this->resource->get('page://self/html/blog-posting?id=0');
        $ro = $this->resource->delete('page://self/html/blog-posting', ['id' => 9999]);
        $this->assertSame(Code::BAD_REQUEST, $ro->code);
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
        $this->assertContains(DonutCacheInterceptor::class, $interceptors);
        assert(isset($ro->bindings['onGet'][0]));
        assert(isset($ro->bindings['onDelete'][0]));
        $this->assertInstanceOf(DonutCacheInterceptor::class, $ro->bindings['onDelete'][0]);
    }
}
