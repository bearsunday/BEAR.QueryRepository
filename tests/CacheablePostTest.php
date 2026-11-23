<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\PostWriter;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * A POST is a write, and a write on a #[Cacheable] class has to invalidate
 *
 * A POST on a cacheable collection is the method a form submits: without an interceptor, the
 * entry it made stale stays served and a `#[Purge]` written on it is dropped without a log event.
 */
class CacheablePostTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);

        PostWriter::$gets = 0;

        parent::setUp();
    }

    public function testPostRefreshesTheRepresentationItMadeStale(): void
    {
        $uri = new Uri('app://self/post-writer?id=1');
        $this->resource->get('app://self/post-writer', ['id' => '1']);
        $stored = $this->repository->get($uri);
        $this->assertInstanceOf(ResourceState::class, $stored);
        $this->assertSame(['id' => '1', 'generation' => 1], $stored->body);

        $this->resource->post('app://self/post-writer', ['id' => '1']);

        $this->assertSame(2, PostWriter::$gets, 'the POST left its own representation cached');
        $refreshed = $this->repository->get($uri);
        $this->assertInstanceOf(ResourceState::class, $refreshed);
        $this->assertSame(['id' => '1', 'generation' => 2], $refreshed->body, 'the pre-POST entry is still the one being served');
    }

    public function testPostRunsThePurgeWrittenOnIt(): void
    {
        $dest = new Uri('app://self/refresh-dest?id=1');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '1']));
        $this->assertInstanceOf(ResourceState::class, $this->repository->get($dest));

        $this->resource->post('app://self/post-writer', ['id' => '1']);

        $this->assertNull($this->repository->get($dest), 'the #[Purge] on the POST was dropped');
    }
}
