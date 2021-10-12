<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

class DonutRepositoryTest extends TestCase
{
    /** @var ResourceInterface */
    private $resource;

    /** @var QueryRepositoryInterface */
    private $queryRepository;

    /** @var DonutRepositoryInterface  */
    private $donutRepository;

    /** @var Uri */
    private $uri;

    public function setUp(): void
    {
        static $injector;

        if (! $injector) {
            $injector = $this->getInjector();
        }

        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $this->queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $uri = 'page://self/html/blog-posting';
        $this->uri = new Uri($uri);
        parent::setUp();
    }

    private function getInjector(): Injector
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace)));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        return new Injector($module, $_ENV['TMP_DIR']);
    }

    public function testCreateDonut(): void
    {
        $maybeNull = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNull);
        // assert cache created in query repository
        $blogPosting = $this->resource->get((string) $this->uri);
        $this->donutRepository->put($blogPosting, null, null);
        $state = $this->queryRepository->get($this->uri);
        $this->assertInstanceOf(ResourceState::class, $state);
    }

    /**
     * @depends testCreateDonut
     */
    public function testCachePurge(): void
    {
        assert($this->queryRepository->purge($this->uri));
        $maybeNullPurged = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNullPurged);
    }

    /**
     * @depends testCreateDonut
     */
    public function testCreatedByDonut(): void
    {
        // create by static
        $donutRo = $this->resource->get('page://self/html/blog-posting');
        assert($donutRo instanceof ResourceObject);
        $this->assertSame('r', $donutRo->headers[Header::ETAG][-1]);
    }

    /**
     * When cache A contains cache B, deleting B will automatically delete A as well.
     */
    public function testCacheDependency(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        $queryRepository = $injector->getInstance(QueryRepository::class);

        // Cache created.
        $resource->get('page://self/html/blog-posting');
        $blogState1 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertInstanceOf(ResourceState::class, $blogState1);
        // When cache dependency is deleted, cache dependent automatically deleted
        assert($queryRepository->purge(new Uri('page://self/html/comment')));
        $blogState2 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertNull($blogState2);
        // Cache created again.
        $resource->get('page://self/html/blog-posting');
        $blogState3 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertInstanceOf(ResourceState::class, $blogState3);
    }

    public function testRefresh(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        assert($resource instanceof ResourceInterface);
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        assert($queryRepository instanceof QueryRepositoryInterface);

        $resource->get('page://self/html/blog-posting');
        assert($queryRepository->purge(new Uri('page://self/html/blog-posting')));
        $donutRo = $resource->get('page://self/html/blog-posting');
        $this->assertSame('r', $donutRo->headers[Header::ETAG][-1]);
    }
}
