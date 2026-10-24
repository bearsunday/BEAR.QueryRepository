<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * Owner-approved regression case for issue #222: a #[Cacheable] response whose OWN code sets
 * Cache-Control to `private` directly (bypassing #[HttpCache]) is still refused the
 * server-side store, but keeps the developer's header - only a mark() forces the package's
 * own `private, no-store` + `Pragma: no-cache` onto it. HttpCacheControlWithCacheable
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

    public function testHandSetPrivateCacheControlPlusAMarkGetsThePackagesOwnHeaders(): void
    {
        // A producer also calling mark() (e.g. the same request issuing a CSRF token
        // elsewhere) still gets the package's own refusal headers, unchanged from before
        // this fix: the hand-set header alone is the owner-approved exception, a mark is not.
        $ro = $this->resource->get('app://self/hand-set-private-cache-control', ['alsoMark' => true]);
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
        $this->assertSame('no-cache', $ro->headers[Header::PRAGMA]);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $ro->headers);
    }
}
