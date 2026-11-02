<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
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
     *  one it matched (#255). */
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

    /** PagedResponseWithId::onPut has no #[Purge]/#[Refresh]. The write's own URI carries both
     *  the required `id` and the defaulted `page`: auto-purge must drop `page` (coarsen) while
     *  keeping `id` (isolate) - a different id's cache must survive untouched. */
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

    /** A bare/required-only URI's own full-query tag is the same string BaseUriTag gives every
     *  sibling variant (that is the mechanism #255 relies on). Storing the bare entry for the
     *  first time (an ordinary cache miss, not a write/purge) must not evict a sibling variant
     *  that was already cached - only an intentional purge/write should reach other variants. */
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

    /** etagPool flavour: the bare URI's first fetch must not kill a sibling's If-None-Match
     *  validator either. */
    public function testBareColdMissStoreDoesNotEvictSiblingVariantEtag(): void
    {
        assert($this->storage instanceof ScopedValidatorInterface);
        $uri = new Uri('app://self/optional-param?id=1&page=2');
        $etag = $this->resource->get('app://self/optional-param?id=1&page=2')->headers['ETag'];

        $this->resource->get('app://self/optional-param?id=1'); // first-ever fetch of the bare URI

        $this->assertTrue($this->storage->hasEtagFor($etag, $uri));
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

    /** A #[CacheableResponse] page's own value entry can expire independently of its donut
     *  template; the next GET re-saves the value via ResourceDonut::refresh(), whose
     *  recomposition keys must still carry the group tag, or a bare-URI purge stops reaching
     *  a refreshed entry's ETag validator even though it still reaches a never-refreshed one. */
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

    /** The donut's SurrogateKeys (not just getTags()/templateKeys()) must carry the group tag
     *  too: it alone builds the etagPool's $surrogateKeys string on the never-refreshed, first
     *  save path (doPutStatic -> setHeaders -> saveView -> saveEtag), so an initially-saved
     *  entry's validator needs it independently of the roPool/template paths. */
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

    /** doPutDonut() (the dynamic #[DonutCache] flavor, template-only) builds its own
     *  SurrogateKeys for the CDN-facing Surrogate-Key response header independently of
     *  templateKeys()'s roPool storage tags, so it needs the group tag added separately too. */
    public function testDynamicDonutCarriesTheGroupTagInItsCdnHeader(): void
    {
        $ro = $this->resource->get('page://self/html/blog-posting-donut?id=5');

        $this->assertStringContainsString('grp-_html_blog-posting-donut_', $ro->headers['Surrogate-Key']);
    }
}
