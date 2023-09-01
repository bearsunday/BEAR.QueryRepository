<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
    private ResourceInterface $resource;
    private QueryRepositoryInterface $queryRepository;
    private DonutRepositoryInterface $donutRepository;
    private Uri $uri;
    private ResourceStorageInterface $resourceStorage;

    public function setUp(): void
    {
        static $injector;

        if (! $injector) {
            $injector = $this->getInjector();
        }

        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $this->queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resourceStorage = $injector->getInstance(ResourceStorageInterface::class);
        $uri = 'page://self/html/blog-posting';
        $this->uri = new Uri($uri);

        parent::setUp();
    }

    private function getInjector(): Injector
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        return new Injector($module, $_ENV['TMP_DIR']);
    }

    public function testCreateDonut(): void
    {
        $maybeNull = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNull);
        // assert cache created in query repository
        $blogPosting = $this->resource->get((string) $this->uri);
        $this->donutRepository->putStatic($blogPosting, null, null);
        $state = $this->queryRepository->get($this->uri);
        $this->assertInstanceOf(ResourceState::class, $state);
    }

    /** @depends testCreateDonut */
    public function testCachePurge(): void
    {
        assert($this->queryRepository->purge($this->uri));
        $maybeNullPurged = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNullPurged);
    }

    /** @depends testCreateDonut */
    public function testCreatedByDonut(): void
    {
        // create donut
        $this->resource->get('page://self/html/blog-posting');
        // delete comment and blog-posting view
        $this->resourceStorage->invalidateTags([(new UriTag())(new Uri('page://self/html/comment'))]);
        // create by donut
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
        assert($queryRepository->purge(new Uri('page://self/html/comment')));
        $donutRo = $resource->get('page://self/html/blog-posting');
        $this->assertSame('r', $donutRo->headers[Header::ETAG][-1]);
    }

    public function testInvalidateTags(): void
    {
        $injector = $this->getInjector();
        $resource = $injector->getInstance(ResourceInterface::class);
        /** @var QueryRepositoryInterface $queryRepository */
        $queryRepository = $injector->getInstance(QueryRepositoryInterface::class);
        /** @var DonutRepositoryInterface $donutRepository */
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $resource->get('page://self/html/blog-posting');
        $ro1 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertInstanceOf(ResourceState::class, $ro1);
        $donutRepository->invalidateTags([(new UriTag())(new Uri('page://self/html/blog-posting'))]);
        $ro2 = $queryRepository->get(new Uri('page://self/html/blog-posting'));
        $this->assertNull($ro2);
    }
}
