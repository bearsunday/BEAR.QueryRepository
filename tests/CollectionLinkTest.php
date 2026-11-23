<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/** A write to a row purges the collection its onGet() links with #[Link(rel: 'collection')] */
class CollectionLinkTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->repository->purge(new Uri('app://self/collection/posts'));
        $this->repository->purge(new Uri('app://self/collection/author-posts?author_id=7'));
        $this->repository->purge(new Uri('app://self/collection/author-posts?author_id=8'));
    }

    /** @return array<string, array{string, array<string, int>}> */
    public static function writeProvider(): array
    {
        return [
            'put' => ['put', ['id' => 1, 'author_id' => 8]],
            'delete' => ['delete', ['id' => 1]],
        ];
    }

    /** @param array<string, int> $query */
    #[DataProvider('writeProvider')]
    public function testWritePurgesEveryVariantOfTheCollection(string $method, array $query): void
    {
        $variants = ['app://self/collection/posts', 'app://self/collection/posts?author_id=7', 'app://self/collection/posts?page=2'];
        foreach ($variants as $uri) {
            $this->resource->get($uri);
            $this->assertNotNull($this->repository->get(new Uri($uri)), "precondition: {$uri} is stored");
        }

        $this->resource->{$method}('app://self/collection/post', $query);

        foreach ($variants as $uri) {
            $this->assertNull($this->repository->get(new Uri($uri)), $uri);
        }
    }

    /** A link of another rel names no purge, and the bare collection does not reach a list keyed by a required parameter */
    public function testOnlyTheCollectionIsPurged(): void
    {
        $authorPosts = 'app://self/collection/author-posts?author_id=8';
        $this->resource->get($authorPosts);

        $this->resource->put('app://self/collection/post', ['id' => 1, 'author_id' => 8]);

        $this->assertNotNull($this->repository->get(new Uri($authorPosts)));
    }

    /** A write that moves a row to another list purges the old list with the value its body carries */
    public function testMovePurgesTheOldListFromTheBody(): void
    {
        $lists = ['app://self/collection/author-posts?author_id=7', 'app://self/collection/author-posts?author_id=8'];
        foreach ($lists as $uri) {
            $this->resource->get($uri);
            $this->assertNotNull($this->repository->get(new Uri($uri)), "precondition: {$uri} is stored");
        }

        $this->resource->patch('app://self/collection/post', ['id' => 1, 'author_id' => 8]);

        foreach ($lists as $uri) {
            $this->assertNull($this->repository->get(new Uri($uri)), $uri);
        }
    }
}
