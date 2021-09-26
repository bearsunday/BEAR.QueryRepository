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

    /** @var QueryRepository */
    private $queryRepository;

    /** @var DonutRepository  */
    private $donutRepository;

    /** @var Uri */
    private $uri;

    public function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace)));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, $_ENV['TMP_DIR']);
        }

        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->donutRepository = $injector->getInstance(DonutRepository::class);
        $this->queryRepository = $injector->getInstance(QueryRepository::class);
        $uri = 'page://self/html/blog-posting';
        $this->uri = new Uri($uri);
        parent::setUp();
    }

    public function testCreateStatic(): void
    {
        $maybeNull = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNull);
        // assert cache created in query repository
        $blogPosting = $this->resource->get((string) $this->uri);
        $this->donutRepository->createDonut($blogPosting, null);
        $state = $this->queryRepository->get($this->uri);
        $this->assertInstanceOf(ResourceState::class, $state);
    }

    public function testPurge(): void
    {
        assert($this->queryRepository->purge($this->uri));
        $maybeNullPurged = $this->queryRepository->get($this->uri);
        $this->assertNull($maybeNullPurged);
    }

    /**
     * @depends testCreateStatic
     */
    public function testCreatedByStatic(): void
    {
        // create by static
        $donutRo = $this->resource->get('page://self/html/blog-posting');
        assert($donutRo instanceof ResourceObject);
        $this->assertSame('r', $donutRo->headers['ETag'][-1]);
    }
}
