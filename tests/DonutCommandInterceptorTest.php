<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
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
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
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
        // Drain the GET sessions first: the assertions below must be satisfied by the
        // delete's own scope, not by an earlier GET's pre-write cleanup.
        $this->flushAndValidate($this->logger);
        $this->resource->delete('page://self/html/blog-posting?id=0');
        $tree = $this->flushAndValidate($this->logger);
        $this->assertContains('command', self::collectTypes($tree), 'the delete opens a command scope');
        $this->assertNotNull(self::eventContextJsonOf($tree, 'invalidate'), 'the write busts the cached entry');
        // onDelete(0) leaves the representation byte-identical, so the entry is rebuilt with the
        // same entity-tag and the client's pre-write validator still matches: an unchanged
        // representation must revalidate to 304. An ETag that changed here would report the
        // write, not the content.
        $this->assertTrue($this->httpCache->isNotModified($server));
        $ro = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $ro->headers);
    }

    public function testCommandInterceptorSkipsRefreshOnErrorCode(): void
    {
        // A failed write must leave the cache exactly as it was. "Still a cache hit
        // afterwards" cannot show that on its own - a refreshed entry is a hit too, with
        // Age: 0 - so the scope itself is read: no invalidation, no refresh, code recorded.
        $this->resource->get('page://self/html/comment');
        $this->flushAndValidate($this->logger); // drain the GET session

        $ro = $this->resource->delete('page://self/html/comment');
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame(Code::BAD_REQUEST, $ro->code);
        $close = self::closeContextJsonOf($tree, 'command_result');
        $this->assertNotNull($close, 'the command scope closes with its outcome');
        $this->assertStringContainsString('"code":400', $close);
        $types = self::collectTypes($tree);
        $this->assertNotContains('invalidate', $types, 'a failed command busts nothing');
        $this->assertNotContains('refresh_same', $types);
        $cached = $this->resource->get('page://self/html/comment');
        $this->assertArrayHasKey('Age', $cached->headers);
    }

    public function testDonutCommandInterceptorSkipsRefreshOnErrorCode(): void
    {
        // The donut sibling of the case above: BlogPosting answers 400 for any id but 0.
        $this->resource->get('page://self/html/blog-posting?id=0');
        $this->flushAndValidate($this->logger); // drain the GET session

        $ro = $this->resource->delete('page://self/html/blog-posting', ['id' => 9999]);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame(Code::BAD_REQUEST, $ro->code);
        $close = self::closeContextJsonOf($tree, 'command_result');
        $this->assertNotNull($close);
        $this->assertStringContainsString('"code":400', $close);
        $types = self::collectTypes($tree);
        $this->assertNotContains('invalidate', $types, 'a failed command busts nothing');
        $this->assertNotContains('refresh_donut', $types);
        $cached = $this->resource->get('page://self/html/blog-posting?id=0');
        $this->assertArrayHasKey('Age', $cached->headers);
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
        // The code field belongs to the error-code reason: a deliberate skip has no code to
        // report, and reporting 200 there would read as a failure that did not happen.
        $this->assertStringContainsString('"code":null', $skipped);
        $close = self::closeContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($close, 'the scope still closes cache_miss (skip, not hit)');
    }

    public function testPutSkippedIsLoggedWithTheCodeWhenTheResponseFails(): void
    {
        // The other half of the skip: ErrorPage answers 400, and the log carries the code so a
        // 400 and a 500 are distinguishable.
        $this->logger->flush(); // drain the setUp session
        $ro = $this->resource->get('page://self/html/error-page');
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame(400, $ro->code);
        $skipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($skipped, 'the failed response explains the missing put');
        $this->assertStringContainsString('"reason":"error-code"', $skipped);
        $this->assertStringContainsString('"code":400', $skipped);
    }

    public function testANon200PageIsNotStored(): void
    {
        // 203 is below the threshold this side used to apply, so the same response was cached
        // here and evicted by `#[Cacheable]`. Only a 200 is stored now (issue #190).
        $this->logger->flush(); // drain the setUp session
        $ro = $this->resource->get('page://self/html/non-authoritative-page');
        $tree = $this->flushAndValidate($this->logger);

        $this->assertSame(203, $ro->code);
        $skipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($skipped, 'the non-200 explains the missing put');
        $this->assertStringContainsString('"reason":"error-code"', $skipped);
        $this->assertStringContainsString('"code":203', $skipped);
        $this->assertNull(self::eventContextJsonOf($tree, 'save_donut'), 'nothing was written');
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
