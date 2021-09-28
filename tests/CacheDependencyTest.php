<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class CacheDependencyTest extends TestCase
{
    /** @var ResourceInterface */
    private $resource;

    /** @var QueryRepository */
    private $repository;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule($namespace))), $_ENV['TMP_DIR']);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        parent::setUp();
    }

    public function testDestroyByChild(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        // destroy by child
        $this->repository->purge(new Uri('page://self/dep/level-two'));
        $one2 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertNull($one2);
    }

    public function testDestroyByGrandChild(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        $this->repository->purge(new Uri('page://self/dep/level-three'));
        $one2 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertNull($one2);
    }
}
