<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function is_array;
use function is_float;

/**
 * A GET's query-variant cache entries (`?page=2`) must be reachable by a purge aimed at the
 * same resource with no query, a required-only query, or a query onGet() does not declare -
 * the shape #[Purge]'s own URI and MatchQuery-based auto-purge both build (#255).
 */
class QueryVariantPurgeTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
    }

    private function time(string $uri): float
    {
        $body = $this->resource->get($uri)->body;
        assert(is_array($body));
        $time = $body['time'];
        assert(is_float($time));

        return $time;
    }

    /** A query onGet() does not declare at all (not merely defaulted). */
    private function timeWithExtraQuery(): float
    {
        $request = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->addQuery(['limit' => 5]); // @phpstan-ignore-line
        assert($request instanceof AbstractRequest);
        $ro = $request->eager->request();
        assert($ro instanceof ResourceObject);
        $body = $ro->body;
        assert(is_array($body));
        $time = $body['time'];
        assert(is_float($time));

        return $time;
    }

    public function testPurgeAttributeReachesDefaultValuedQueryVariant(): void
    {
        $bare = $this->time('app://self/optional-param?id=1');
        $page2 = $this->time('app://self/optional-param?id=1&page=2');
        $this->assertSame($bare, $this->time('app://self/optional-param?id=1'), 'precondition: warm cache');
        $this->assertSame($page2, $this->time('app://self/optional-param?id=1&page=2'), 'precondition: warm cache');

        $this->resource->put('app://self/optional-param', ['id' => 1]);

        $this->assertNotSame($bare, $this->time('app://self/optional-param?id=1'));
        $this->assertNotSame($page2, $this->time('app://self/optional-param?id=1&page=2'));
    }

    public function testDirectPurgeReachesDefaultValuedQueryVariant(): void
    {
        $bare = $this->time('app://self/optional-param?id=1');
        $page2 = $this->time('app://self/optional-param?id=1&page=2');
        $this->assertSame($bare, $this->time('app://self/optional-param?id=1'), 'precondition: warm cache');
        $this->assertSame($page2, $this->time('app://self/optional-param?id=1&page=2'), 'precondition: warm cache');

        $this->repository->purge(new Uri('app://self/optional-param?id=1'));

        $this->assertNotSame($bare, $this->time('app://self/optional-param?id=1'));
        $this->assertNotSame($page2, $this->time('app://self/optional-param?id=1&page=2'));
    }

    /** A query onGet() does not declare at all (not merely defaulted) must drop too. */
    public function testAutoPurgeReachesQueryNotInOnGetSignature(): void
    {
        $plain = $this->time('app://self/user?id=1');
        $before = $this->timeWithExtraQuery();
        $this->assertSame($plain, $this->time('app://self/user?id=1'), 'precondition: warm cache');
        $this->assertSame($before, $this->timeWithExtraQuery(), 'precondition: warm cache');

        $this->resource->delete('app://self/user', ['id' => 1]);

        $this->assertNotSame($plain, $this->time('app://self/user?id=1'));
        $this->assertNotSame($before, $this->timeWithExtraQuery());
    }

    public function testDonutAutoPurgeReachesDefaultValuedQueryVariant(): void
    {
        $bare = (string) $this->resource->get('page://self/paged-list');
        $page2 = (string) $this->resource->get('page://self/paged-list?page=2');
        $this->assertSame($bare, (string) $this->resource->get('page://self/paged-list'), 'precondition: warm cache');
        $this->assertSame($page2, (string) $this->resource->get('page://self/paged-list?page=2'), 'precondition: warm cache');

        $this->resource->post('page://self/paged-list', ['title' => 'new']);

        $this->assertNotSame($bare, (string) $this->resource->get('page://self/paged-list'));
        $this->assertNotSame($page2, (string) $this->resource->get('page://self/paged-list?page=2'));
    }

    /** PagedList::onPost has no #[Purge]/#[Refresh]; DonutCommandInterceptor's MatchQuery-driven
     *  auto-purge is the only invalidation path. Posting straight to `?page=2` (the write's own
     *  URI carries the defaulted param) must still invalidate every page variant, not only the
     *  one it matched (#255 / CodeRabbit). */
    public function testDonutAutoPurgeFromWriteUriCarryingDefaultedParamReachesOtherVariants(): void
    {
        $bare = (string) $this->resource->get('page://self/paged-list');
        $page1 = (string) $this->resource->get('page://self/paged-list?page=1');
        $this->assertSame($bare, (string) $this->resource->get('page://self/paged-list'), 'precondition: warm cache');
        $this->assertSame($page1, (string) $this->resource->get('page://self/paged-list?page=1'), 'precondition: warm cache');

        $this->resource->post('page://self/paged-list?page=2', ['title' => 'new']);

        $this->assertNotSame($bare, (string) $this->resource->get('page://self/paged-list'));
        $this->assertNotSame($page1, (string) $this->resource->get('page://self/paged-list?page=1'));
    }

    /** PagedValue::onPut has no #[Purge]/#[Refresh]; RefreshSameCommand's MatchQuery-driven
     *  auto-purge is the only invalidation path. Same scenario as above for a plain
     *  #[Cacheable] value resource (not a donut page). */
    public function testValueAutoPurgeFromWriteUriCarryingDefaultedParamReachesOtherVariants(): void
    {
        $bare = $this->time('app://self/paged-value');
        $page1 = $this->time('app://self/paged-value?page=1');
        $this->assertSame($bare, $this->time('app://self/paged-value'), 'precondition: warm cache');
        $this->assertSame($page1, $this->time('app://self/paged-value?page=1'), 'precondition: warm cache');

        $this->resource->put('app://self/paged-value?page=2');

        $this->assertNotSame($bare, $this->time('app://self/paged-value'));
        $this->assertNotSame($page1, $this->time('app://self/paged-value?page=1'));
    }

    /** The etagPool computes its own tag list (ResourceStorage::saveEtag), independent of
     *  getTags(); a bare purge must invalidate its If-None-Match validator too, or a
     *  conditional GET keeps returning 304 for a body the roPool already dropped. */
    public function testBarePurgeInvalidatesQueryVariantEtag(): void
    {
        assert($this->storage instanceof ScopedValidatorInterface);
        $uri = new Uri('app://self/optional-param?id=1&page=2');
        $ro = $this->resource->get('app://self/optional-param?id=1&page=2');
        $etag = $ro->headers['ETag'];
        $this->assertTrue($this->storage->hasEtagFor($etag, $uri), 'precondition: validator registered');

        $this->repository->purge(new Uri('app://self/optional-param?id=1'));

        $this->assertFalse($this->storage->hasEtagFor($etag, $uri));
    }
}
