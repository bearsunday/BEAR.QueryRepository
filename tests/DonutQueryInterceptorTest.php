<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\Page\Html\BlogPosting;
use FakeVendor\HelloWorld\Resource\Page\Html\Comment;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;
use function trim;

class DonutQueryInterceptorTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

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
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);

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
        // DevEtagSetter uses the URI tag as ETag; strip the entity-tag DQUOTEs to compare with surrogate keys
        $commentEtag = trim($comment->headers[Header::ETAG], '"');
        $this->assertStringContainsString($commentEtag, $surrogateKey);
    }

    /** @depends testInitialRequest */
    public function testCached(): void
    {
        $this->logger->flush(); // drain any prior session

        $blogPosting = $this->resource->get('page://self/html/blog-posting');
        assert($blogPosting instanceof BlogPosting);

        // The rendered page is served from cache on the second access: the GET scope
        // closes as a hit and the whole tree validates against the context schemas.
        $tree = $this->flushAndValidate($this->logger);
        $types = self::collectTypes($tree);
        $this->assertContains('get', $types);
        $this->assertContains('cache_hit', $types);

        $this->assertArrayHasKey('Age', $blogPosting->headers);
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }

    public function testCreatDonutInResourceObject(): void
    {
        $blogPostingCacheControl = $this->resource->get('page://self/html/blog-posting-cache-control');
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $blogPostingCacheControl->headers);
    }
}
