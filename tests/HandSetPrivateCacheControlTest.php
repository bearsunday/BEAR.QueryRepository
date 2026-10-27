<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\Page\Html\BlogPostingCacheControl;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * Owner-approved regression case: a #[Cacheable] response whose OWN code sets Cache-Control
 * to `private` directly (bypassing #[HttpCache]) is still refused the server-side store, but
 * keeps the developer's header. HttpCacheControlWithCacheable
 * (GetInterceptorTest::testHttpCacheWithCacheble) already covers the #[HttpCache]-declared
 * exemption: that value IS stored.
 */
class HandSetPrivateCacheControlTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = ModuleFactory::getInstance($namespace);
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testHandSetPrivateCacheControlIsNotStoredButKeepsItsOwnHeader(): void
    {
        $first = $this->resource->get('app://self/hand-set-private-cache-control');
        $this->assertSame('private', $first->headers[Header::CACHE_CONTROL], "the developer's own header is kept exactly - no max-age appended, no overwrite to private, no-store");
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $first->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $first->headers);

        // Not stored server-side: a second GET re-runs onGet() (Age is set only when a
        // GET is served from a previously stored state - QueryRepository::get()).
        $second = $this->resource->get('app://self/hand-set-private-cache-control');
        $this->assertSame('private', $second->headers[Header::CACHE_CONTROL]);
        $this->assertArrayNotHasKey('Age', $second->headers, 'a cache hit would carry Age; onGet() ran again instead');
    }

    public function testHandSetNoStoreOnADonutResponseKeepsItsHeaderAndStripsCdnHeaders(): void
    {
        // BlogPostingCacheControl (#[CacheableResponse]) hand-sets Cache-Control: no-store
        // via a plain property default (bypassing #[HttpCache]) - the donut-path sibling of
        // the #[Cacheable] case above. DonutRepository::setHeaders() sets Surrogate-Key
        // unconditionally before HeaderSetter runs, so this is not a vacuous check: it
        // proves the CDN-facing headers are actually stripped, not merely absent.
        BlogPostingCacheControl::$calls = 0;
        $ro = $this->resource->get('page://self/html/blog-posting-cache-control');
        $this->assertSame('no-store', $ro->headers[Header::CACHE_CONTROL], "the developer's own header is kept exactly");
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $ro->headers);

        // Not stored server-side: a second GET re-runs onGet() instead of recomposing
        // from a saved donut.
        $this->resource->get('page://self/html/blog-posting-cache-control');
        $this->assertSame(2, BlogPostingCacheControl::$calls, 'a stored donut would answer the second GET without running onGet() again');
    }
}
