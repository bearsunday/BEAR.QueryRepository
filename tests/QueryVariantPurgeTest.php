<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\PagedErrorOnBare;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function assert;
use function is_array;
use function is_float;
use function is_string;

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

    /** Auto-purge also reaches a query that onGet() does not declare at all. */
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

    /** PagedList::onPost has no #[Purge]/#[Refresh], so DonutCommandInterceptor's auto-purge is the only
     *  invalidation: a write to `?page=2` reaches every page variant. */
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

    /** Same for a plain #[Cacheable] value resource: PagedValue::onPut has no #[Purge]/#[Refresh], so
     *  RefreshSameCommand's auto-purge is the only invalidation. */
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

    /** The write's URI carries the required `id` and the defaulted `page`: auto-purge drops `page` and
     *  keeps `id`, so another id's cache survives. */
    public function testDonutAutoPurgeKeepsRequiredParamWhileDroppingDefaultedOne(): void
    {
        $id1Bare = (string) $this->resource->get('page://self/paged-response-with-id?id=1');
        $id1Page2 = (string) $this->resource->get('page://self/paged-response-with-id?id=1&page=2');
        $id2Page2 = (string) $this->resource->get('page://self/paged-response-with-id?id=2&page=2');
        $this->assertSame($id1Bare, (string) $this->resource->get('page://self/paged-response-with-id?id=1'), 'precondition: warm cache');
        $this->assertSame($id1Page2, (string) $this->resource->get('page://self/paged-response-with-id?id=1&page=2'), 'precondition: warm cache');

        $this->resource->put('page://self/paged-response-with-id?id=1&page=2');

        $this->assertNotSame($id1Bare, (string) $this->resource->get('page://self/paged-response-with-id?id=1'));
        $this->assertNotSame($id1Page2, (string) $this->resource->get('page://self/paged-response-with-id?id=1&page=2'));
        $this->assertSame($id2Page2, (string) $this->resource->get('page://self/paged-response-with-id?id=2&page=2'), 'a different id must not be purged');
    }

    /** Storing the bare URI on a cache miss (no write, no purge) must not evict an already-cached
     *  sibling variant. */
    public function testBareColdMissStoreDoesNotEvictSiblingVariant(): void
    {
        $page2 = $this->time('app://self/optional-param?id=1&page=2');
        $this->assertSame($page2, $this->time('app://self/optional-param?id=1&page=2'), 'precondition: warm cache');

        $this->time('app://self/optional-param?id=1'); // first-ever fetch of the bare URI

        $this->assertSame($page2, $this->time('app://self/optional-param?id=1&page=2'));
    }

    /** Donut flavour of the above. */
    public function testBareDonutColdMissStoreDoesNotEvictSiblingVariant(): void
    {
        $page2 = (string) $this->resource->get('page://self/paged-list?page=2');
        $this->assertSame($page2, (string) $this->resource->get('page://self/paged-list?page=2'), 'precondition: warm cache');

        $this->resource->get('page://self/paged-list'); // first-ever fetch of the bare URI

        $this->assertSame($page2, (string) $this->resource->get('page://self/paged-list?page=2'));
    }

    /** etagPool flavour: the bare URI's first fetch keeps the sibling's If-None-Match validator. */
    public function testBareColdMissStoreDoesNotEvictSiblingVariantEtag(): void
    {
        assert($this->storage instanceof ScopedValidatorInterface);
        $uri = new Uri('app://self/optional-param?id=1&page=2');
        $etag = $this->resource->get('app://self/optional-param?id=1&page=2')->headers['ETag'];

        $this->resource->get('app://self/optional-param?id=1'); // first-ever fetch of the bare URI

        $this->assertTrue($this->storage->hasEtagFor($etag, $uri));
    }

    /** A bare purge also invalidates the variant's If-None-Match validator; the etagPool builds its tag
     *  list apart from getTags(). */
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

    /** After ResourceDonut::refresh() re-saves a page's value (its template survived), the ETag validator
     *  still carries the group tag. */
    public function testDonutRefreshKeepsTheGroupTagOnTheEtagValidator(): void
    {
        assert($this->storage instanceof ScopedValidatorInterface);
        $uri = new Uri('page://self/html/paged-static-donut');
        $this->resource->get((string) $uri); // the interceptor's own putStatic() warms both pools

        // Drop just the value/etag entry, leaving the donut template behind, by reaching into
        // ResourceStorage's internal pool directly - no public API removes only one of the two.
        $roPool = (new ReflectionProperty($this->storage, 'roPool'))->getValue($this->storage);
        assert($roPool instanceof TagAwareAdapterInterface);
        $getUriKey = new ReflectionMethod($this->storage, 'getUriKey');
        $getUriKey->setAccessible(true);
        $key = $getUriKey->invoke($this->storage, $uri, 'ro-');
        assert(is_string($key));
        $roPool->deleteItem($key);
        $this->assertNull($this->repository->get($uri), 'precondition: value entry gone');
        $this->assertNotNull($this->storage->getDonut($uri), 'precondition: donut template still present');

        $refreshed = $this->resource->get((string) $uri);
        $etag = $refreshed->headers['ETag'];
        $this->assertTrue($this->storage->hasEtagFor($etag, $uri), 'precondition: refreshed validator registered');

        $this->storage->invalidateTags(['grp-_html_paged-static-donut_']);

        $this->assertFalse($this->storage->hasEtagFor($etag, $uri));
    }

    /** On the first, never-refreshed save, doPutStatic()'s SurrogateKeys is what tags the etagPool entry,
     *  so it must carry the group tag itself. */
    public function testDonutInitialSaveTagsTheEtagValidatorWithTheGroupTag(): void
    {
        assert($this->storage instanceof ScopedValidatorInterface);
        $uri = new Uri('page://self/html/paged-static-donut');
        $ro = $this->resource->get((string) $uri);
        $etag = $ro->headers['ETag'];
        $this->assertTrue($this->storage->hasEtagFor($etag, $uri), 'precondition: validator registered');

        $this->storage->invalidateTags(['grp-_html_paged-static-donut_']);

        $this->assertFalse($this->storage->hasEtagFor($etag, $uri));
    }

    /** doPutDonut() (dynamic #[DonutCache], template-only) builds the CDN Surrogate-Key header from its own
     *  SurrogateKeys, separate from templateKeys(), so it carries the group tag itself. */
    public function testDynamicDonutCarriesTheGroupTagInItsCdnHeader(): void
    {
        $ro = $this->resource->get('page://self/html/blog-posting-donut?id=5');

        $this->assertStringContainsString('grp-_html_blog-posting-donut_', $ro->headers['Surrogate-Key']);
    }

    /** An error response invalidates its own URI's tag (e.g. a parent embedding it) but not the group tag. */
    public function testErrorOnBareUriKeepsQueryVariantsCached(): void
    {
        $page2 = $this->time('app://self/paged-error-on-bare?page=2');
        $this->assertSame($page2, $this->time('app://self/paged-error-on-bare?page=2'), 'precondition: warm cache');
        $roPool = (new ReflectionProperty($this->storage, 'roPool'))->getValue($this->storage);
        assert($roPool instanceof TagAwareAdapterInterface);
        $dependent = $roPool->getItem('dependent-on-bare');
        $dependent->set(true)->tag((new UriTag())(new Uri('app://self/paged-error-on-bare')));
        $this->assertTrue($roPool->save($dependent), 'precondition: dependent entry stored');

        PagedErrorOnBare::$fail = true;
        try {
            $this->assertSame(503, $this->resource->get('app://self/paged-error-on-bare')->code);
        } finally {
            PagedErrorOnBare::$fail = false;
        }

        $this->assertFalse($roPool->hasItem('dependent-on-bare'));
        $this->assertSame($page2, $this->time('app://self/paged-error-on-bare?page=2'));
    }

    /** A parent depends on its child's group, so a bare purge of the child reaches a parent that embeds a variant. */
    public function testBarePurgeReachesACacheableParentEmbeddingAQueryVariant(): void
    {
        $parent = $this->time('page://self/variant-parent');
        $this->assertSame($parent, $this->time('page://self/variant-parent'), 'precondition: warm cache');

        $this->repository->purge(new Uri('app://self/optional-param?id=1'));

        $this->assertNotSame($parent, $this->time('page://self/variant-parent'));
    }

    public function testBarePurgeReachesADonutPageReadingAQueryVariant(): void
    {
        $page = (string) $this->resource->get('page://self/html/variant-donut-parent');
        $this->assertSame($page, (string) $this->resource->get('page://self/html/variant-donut-parent'), 'precondition: warm cache');

        $this->repository->purge(new Uri('app://self/optional-param?id=1'));

        $this->assertNotSame($page, (string) $this->resource->get('page://self/html/variant-donut-parent'));
    }

    public function testWriteToTheChildReachesAParentEmbeddingAnotherQueryVariant(): void
    {
        $parent = $this->time('page://self/paged-value-parent');
        $this->assertSame($parent, $this->time('page://self/paged-value-parent'), 'precondition: warm cache');

        $this->resource->put('app://self/paged-value', ['page' => 1]);

        $this->assertNotSame($parent, $this->time('page://self/paged-value-parent'));
    }

    /** A purge URI that spells a default value is not coarsened: it reaches that entry only. */
    public function testPurgeSpellingADefaultValueReachesThatVariantOnly(): void
    {
        $page1 = $this->time('app://self/optional-param?id=1&page=1');
        $page2 = $this->time('app://self/optional-param?id=1&page=2');

        $this->repository->purge(new Uri('app://self/optional-param?id=1&page=1'));

        $this->assertNotSame($page1, $this->time('app://self/optional-param?id=1&page=1'));
        $this->assertSame($page2, $this->time('app://self/optional-param?id=1&page=2'));
    }

    /** BEAR.Resource binds `?user_id=` to `$userId`; the group keeps the required key as the request spelled it. */
    public function testBarePurgeWithSnakeCaseKeysReachesThatUsersVariantsOnly(): void
    {
        $user1 = $this->time('app://self/snake-query-param?user_id=1&page=2');
        $user2 = $this->time('app://self/snake-query-param?user_id=2&page=2');

        $this->repository->purge(new Uri('app://self/snake-query-param?user_id=1'));

        $this->assertNotSame($user1, $this->time('app://self/snake-query-param?user_id=1&page=2'));
        $this->assertSame($user2, $this->time('app://self/snake-query-param?user_id=2&page=2'));
    }

    /** A camelCase key (`?userId=`) stays camelCase in the group. */
    public function testBarePurgeWithCamelCaseKeysReachesThatUsersVariantsOnly(): void
    {
        $user1 = $this->time('app://self/snake-query-param?userId=1&page=2');
        $user2 = $this->time('app://self/snake-query-param?userId=2&page=2');
        $this->assertSame($user1, $this->time('app://self/snake-query-param?userId=1&page=2'), 'precondition: warm cache');

        $this->repository->purge(new Uri('app://self/snake-query-param?userId=1'));

        $this->assertNotSame($user1, $this->time('app://self/snake-query-param?userId=1&page=2'));
        $this->assertSame($user2, $this->time('app://self/snake-query-param?userId=2&page=2'));
    }
}
