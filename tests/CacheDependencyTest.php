<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function explode;

class CacheDependencyTest extends TestCase
{
    private \BEAR\Resource\ResourceInterface $resource;

    private \BEAR\QueryRepository\QueryRepositoryInterface $repository;

    private \BEAR\QueryRepository\ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)), $_ENV['TMP_DIR']);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        parent::setUp();
    }

    public function testDestroyByChild(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        assert($one1 instanceof ResourceState);
        $etag1 = $one1->headers[Header::ETAG];
        // destroy by child
        $this->repository->purge(new Uri('page://self/dep/level-two'));
        $one2 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertNull($one2);
        $this->assertFalse($this->storage->hasEtag($etag1));
    }

    public function testDestroyByGrandChild(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        $this->repository->purge(new Uri('page://self/dep/level-three'));
        $one2 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertNull($one2);
        assert($one1 instanceof ResourceState);
        $etag1 = $one1->headers[Header::ETAG];
        $surrogateKeys = explode(' ', $one1->headers['Surrogate-Key']);
        $etag2 = $surrogateKeys[0];
        $etag3 = $surrogateKeys[1];
        $this->assertFalse($this->storage->hasEtag($etag1));
        $this->assertFalse($this->storage->hasEtag($etag2));
        $this->assertFalse($this->storage->hasEtag($etag3));
    }
}
