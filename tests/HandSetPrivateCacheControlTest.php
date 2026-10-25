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

    public function testHandSetPrivateCacheControlKeepsItsOwnHeaderWhenAnUnrelatedMarkFiredElsewhereInTheRequest(): void
    {
        // CodeRabbit review (thread on 5e54c1a): HeaderSetter's own "was it a mark" check
        // must use the SAME own-write-scoped predicate reasonForOwnWrite() used to decide
        // $unstorable, not any-owner isMarked() - otherwise an UNRELATED mark elsewhere in
        // the same request (an unrelated hole's own mark, already closed before this
        // resource's own write opens - neither isMarkedForCurrentScope() nor
        // wasMarkedSinceCurrentWrite() catches it) wrongly replaces this resource's own,
        // deliberately hand-set `Cache-Control: private` with the package's `private,
        // no-store` + Pragma, even though THIS write was refused only because of its own
        // header, never because of that unrelated mark.
        $namespace = 'FakeVendor\HelloWorld';
        $module = ModuleFactory::getInstance($namespace);
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $scope = $injector->getInstance(UncacheableMarkScopeInterface::class);

        $scope->enterHole('page://self/html/unrelated-hole');
        $scope->mark('csrf-token-issued');
        $scope->leaveHole('page://self/html/unrelated-hole');

        $ro = $resource->get('app://self/hand-set-private-cache-control');
        $this->assertSame('private', $ro->headers[Header::CACHE_CONTROL], "the developer's own header must be kept - this write was refused by its own Cache-Control, not by the unrelated mark");
        $this->assertArrayNotHasKey(Header::PRAGMA, $ro->headers, 'Pragma: no-cache is only ever added for an actual mark reason');
    }

    public function testHandSetNoStoreOnADonutResponseKeepsItsHeaderAndStripsCdnHeaders(): void
    {
        // BlogPostingCacheControl (#[CacheableResponse]) hand-sets Cache-Control: no-store
        // via a plain property default (bypassing #[HttpCache]) - the donut-path sibling of
        // the #[Cacheable] case above. DonutRepository::setHeaders() sets Surrogate-Key
        // unconditionally before HeaderSetter runs, so this is not a vacuous check: it
        // proves the CDN-facing headers are actually stripped, not merely absent.
        $ro = $this->resource->get('page://self/html/blog-posting-cache-control');
        $this->assertSame('no-store', $ro->headers[Header::CACHE_CONTROL], "the developer's own header is kept exactly");
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $ro->headers);
    }
}
