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
use function is_string;

class DonutCacheInterceptorTest extends TestCase
{
    use SchemaValidationTrait;

    private ResourceInterface $resource;
    private StructuredRepositoryLoggerInterface $logger;

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
        $logger = $injector->getInstance(RepositoryLoggerInterface::class);
        assert($logger instanceof StructuredRepositoryLoggerInterface);
        $this->logger = $logger;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->assertLogValidatesSchema($this->logger);
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
        $this->logger->log('request-start', ['uri' => 'page://self/html/blog-posting-donut', 'method' => 'get']);
        $blogPosting = $this->resource->get('page://self/html/blog-posting-donut');
        assert($blogPosting instanceof BlogPostingDonut);
        // Verify key operations structurally via getOps() (order-preserving, not substring matching)
        $ops = $this->logger->getOps();
        foreach (['try-donut-view', 'try-donut', 'cache-miss', 'cache-hit', 'put-donut', 'put-query-repository', 'save-etag', 'save-value', 'save-donut', 'refresh-donut'] as $op) {
            $this->assertContains($op, $ops);
        }

        // The donut structure is served from cache on the second access (layer: donut)
        $this->assertContains('donut', $this->layersFor('cache-hit'));
        $this->assertArrayNotHasKey('Age', $blogPosting->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $blogPosting->headers);
    }

    /**
     * Collect the "layer" values recorded for a given op
     *
     * @return list<string>
     */
    private function layersFor(string $op): array
    {
        $layers = [];
        foreach ($this->logger->getLogs() as $log) {
            if ($log['op'] === $op && isset($log['layer']) && is_string($log['layer'])) {
                $layers[] = $log['layer'];
            }
        }

        return $layers;
    }
}
