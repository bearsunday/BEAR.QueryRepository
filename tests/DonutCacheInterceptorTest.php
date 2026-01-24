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
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof Injector);
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
        // Verify key operations in JSON log format
        $this->assertStringContainsString('"op":"try-donut-view"', $log);
        $this->assertStringContainsString('"op":"try-donut"', $log);
        $this->assertStringContainsString('"op":"no-donut-found"', $log);
        $this->assertStringContainsString('"op":"put-donut"', $log);
        $this->assertStringContainsString('"op":"put-query-repository"', $log);
        $this->assertStringContainsString('"op":"save-etag"', $log);
        $this->assertStringContainsString('"op":"save-value"', $log);
        $this->assertStringContainsString('"op":"save-donut"', $log);
        $this->assertStringContainsString('"op":"refresh-donut"', $log);
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }
}
