<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

class DonutQueryInterceptorPurgeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepository $repository;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof Injector);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepository::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->flushAndValidate($this->logger);
    }

    public function testStatePurge(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $this->assertFalse($this->isCreatedByState($ro1));
        $this->assertTrue($this->isStateCached());
        $puregeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($puregeResult);
        $this->assertFalse($this->isStateCached());

        $ro2 = $this->resource->get('page://self/html/blog-posting');
        $this->assertTrue($this->isCreatedByState($ro2));
        $this->assertTrue($this->isStateCached(), 'Resource state should be cached');
    }

    private function isStateCached(): bool
    {
        return $this->repository->get(new Uri('page://self/html/blog-posting')) instanceof ResourceState;
    }

    private function isCreatedByState(ResourceObject $ro): bool
    {
        return $ro->headers[Header::ETAG][-1] === 'r';
    }
}
