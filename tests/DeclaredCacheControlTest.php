<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\App\HttpCacheControlWithCacheable;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;

class DeclaredCacheControlTest extends TestCase
{
    public function testNoCacheControlIsStorable(): void
    {
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new None();

        $this->assertNull($declaredCacheControl->reason($ro));
    }

    public function testCacheControlNoStoreIsUnstorable(): void
    {
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'no-store';

        $this->assertSame('unstorable', $declaredCacheControl->reason($ro));
    }

    public function testCacheControlPrivateIsUnstorable(): void
    {
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'PRIVATE';

        $this->assertSame('unstorable', $declaredCacheControl->reason($ro), 'RFC 9111 directives are case-insensitive');
    }

    public function testCacheControlHeaderNameIsMatchedCaseInsensitively(): void
    {
        // $ro->headers is a plain PHP array, not an HTTP-aware collection - 'cache-control'
        // must be found the same way 'Cache-Control' is (RFC 7230 §3.2).
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new None();
        $ro->headers['cache-control'] = 'no-store';

        $this->assertSame('unstorable', $declaredCacheControl->reason($ro));
    }

    public function testCacheControlMatchingTheDeclaredValueIsExempt(): void
    {
        // #[HttpCache(isPrivate: true)]'s own value is the attribute speaking, not the
        // method - #[Cacheable] still stores server-side.
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new HttpCacheControlWithCacheable();
        $ro->headers[Header::CACHE_CONTROL] = 'private';

        $this->assertNull($declaredCacheControl->reason($ro));
    }

    public function testCacheControlDivergingFromTheDeclaredValueIsNotExempt(): void
    {
        // A method or render that adds its own no-store on top of (or instead of) the
        // declared value still trips the check.
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new HttpCacheControlWithCacheable();
        $ro->headers[Header::CACHE_CONTROL] = 'private, no-store';

        $this->assertSame('unstorable', $declaredCacheControl->reason($ro));
    }

    public function testOrdinaryCacheControlIsStorable(): void
    {
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'max-age=60';

        $this->assertNull($declaredCacheControl->reason($ro));
    }

    public function testEveryCaseVariantOfTheCacheControlKeyIsChecked(): void
    {
        // Two components writing differently-cased keys leave two Cache-Control entries in
        // the plain array; a private one must be refused whichever key comes first.
        $declaredCacheControl = new DeclaredCacheControl();
        $ro = new None();
        $ro->headers = ['Cache-Control' => 'public', 'cache-control' => 'private'];
        $this->assertSame('unstorable', $declaredCacheControl->reason($ro));

        $reversed = new None();
        $reversed->headers = ['cache-control' => 'private', 'Cache-Control' => 'public'];
        $this->assertSame('unstorable', $declaredCacheControl->reason($reversed));
    }
}
