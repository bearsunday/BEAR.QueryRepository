<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Code;
use BEAR\Resource\ResourceInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface as HttpCacheInterfaceAlias;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function assert;
use function dirname;
use function is_array;
use function property_exists;

class DonutCommandInterceptorTest extends TestCase
{
    use SemanticLogTreeTrait;

    protected ResourceInterface $resource;
    protected SemanticLoggerInterface $logger;
    protected HttpCacheInterfaceAlias $httpCache;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterfaceAlias::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->flushAndValidate($this->logger);
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
        $this->resource->delete('page://self/html/blog-posting?id=0');
        $this->assertFalse($this->httpCache->isNotModified($server));
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

    public function testPutSkippedIsLoggedWhenResponseAlreadyHasEtag(): void
    {
        // SelfEtag presets its own ETag in onGet: the miss is intentionally NOT followed
        // by a put, and the log must say so instead of looking like a lost write.
        $this->logger->flush(); // drain the setUp session
        $this->resource->get('page://self/html/self-etag');
        $tree = $this->flushAndValidate($this->logger);

        $skipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($skipped, 'the intentional skip is recorded');
        $this->assertStringContainsString('"reason":"etag-present"', $skipped);
        $close = self::closeContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($close, 'the scope still closes cache_miss (skip, not hit)');
    }

    public function testSaveDonutLogsHeaderTags(): void
    {
        // putStatic tags the donut entry with the Surrogate-Key header keys captured at
        // put time; BlogPosting sets 'blog-posting-page' in onGet.
        $this->resource->get('page://self/html/blog-posting?id=0');
        $tree = $this->flushAndValidate($this->logger);

        $saveDonut = self::eventContextJsonOf($tree, 'save_donut');
        $this->assertNotNull($saveDonut);
        $this->assertStringContainsString('"blog-posting-page"', $saveDonut);

        // save_donut_view records its invalidation tags, including the resource's URI tag.
        $saveDonutView = self::eventContextJsonOf($tree, 'save_donut_view');
        $this->assertNotNull($saveDonutView);
        $this->assertStringContainsString('"_html_blog-posting_id=0"', $saveDonutView);
    }

    public function testCacheableResponse(): void
    {
        $ro = $this->resource->get('page://self/html/blog-posting-cache?id=0');
        $interceptors = array_map(static fn (object $object): string => $object::class, $ro->bindings['onGet']); // @phpstan-ignore-line
        $this->assertContains(DonutCacheInterceptor::class, $interceptors);
        assert(property_exists($ro, 'bindings'));
        assert(is_array($ro->bindings));
        assert(is_array($ro->bindings['onGet']));
        assert(is_array($ro->bindings['onDelete']));
        assert(isset($ro->bindings['onGet'][0]));
        assert(isset($ro->bindings['onDelete'][0]));
        $this->assertInstanceOf(DonutCacheInterceptor::class, $ro->bindings['onDelete'][0]);
    }
}
