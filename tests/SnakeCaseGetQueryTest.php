<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * The automatic invalidation after a write reaches an entry a GET stored with onGet()'s parameter
 * spelled in snake_case (`?user_id=1` for `$userId`) (#275).
 */
class SnakeCaseGetQueryTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
    }

    /** Compared as rendered: a #[CacheableResponse] GET served from cache has no array body. */
    private function view(string $uri): string
    {
        return (string) $this->resource->get($uri);
    }

    /** @return array<string, array{string, 'put'|'post'}> */
    public static function writeProvider(): array
    {
        return [
            '#[Cacheable] PUT' => ['app://self/user-posts', 'put'],
            '#[Cacheable] POST' => ['app://self/user-posts', 'post'],
            '#[CacheableResponse] PUT' => ['app://self/user-posts-response', 'put'],
            '#[CacheableResponse] POST' => ['app://self/user-posts-response', 'post'],
        ];
    }

    /** @param 'put'|'post' $method */
    #[DataProvider('writeProvider')]
    public function testWriteInvalidatesAnEntryGetStoredWithTheSnakeCaseKey(string $uri, string $method): void
    {
        $user1 = $this->view("{$uri}?user_id=1");
        $user2 = $this->view("{$uri}?user_id=2");
        $this->assertSame($user1, $this->view("{$uri}?user_id=1"), 'precondition: warm cache');

        $query = ['userId' => 1];
        $ro = $method === 'put' ? $this->resource->put($uri, $query) : $this->resource->post($uri, $query);

        $this->assertSame(200, $ro->code);
        $this->assertNotSame($user1, $this->view("{$uri}?user_id=1"));
        $this->assertSame($user2, $this->view("{$uri}?user_id=2"));
    }
}
