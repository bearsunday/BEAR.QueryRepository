<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\Html\CacheableResponseNoStore;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;

class HeaderSetterTest extends TestCase
{
    private function headerSetter(): HeaderSetter
    {
        return new HeaderSetter(new EtagSetter());
    }

    public function testStripHeadersMatchesALowercaseCdnCacheControlHeaderName(): void
    {
        // $ro->headers is a plain PHP array, not an HTTP-aware collection (RFC 7230 §3.2):
        // an application that wrote 'cdn-cache-control' (lowercase) must not survive an
        // unstorable refusal just because it does not match the exact canonical casing.
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        ($this->headerSetter())($ro, null, null);

        $this->assertArrayNotHasKey('cdn-cache-control', $ro->headers);
        $this->assertSame('private', $ro->headers[Header::CACHE_CONTROL], "the developer's own header is kept exactly");
    }

    public function testStripHeadersRemovesEveryCasingWhenTheSameHeaderIsSetTwice(): void
    {
        // $ro->headers is a plain array, so 'Cache-Control' and 'cache-control' can coexist
        // as two distinct keys. stripHeaders() must remove ALL of them, not just the first
        // match - a single-match lookup here would leave one behind, a lifetime header
        // surviving the unstorable refusal.
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';
        $ro->headers[Header::CDN_CACHE_CONTROL] = 'max-age=600';
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        ($this->headerSetter())($ro, null, null);

        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertArrayNotHasKey('cdn-cache-control', $ro->headers);
    }

    public function testSetCacheControlMaxAgeFindsAnExistingLowercaseCacheControlHeaderName(): void
    {
        // An app-set 'cache-control' (lowercase) must be recognized as the SAME header an
        // exact-key isset() would miss - otherwise this setter adds a second, canonically-
        // cased Cache-Control header alongside it instead of appending to the existing one.
        $ro = new None();
        $ro->headers['cache-control'] = 'public';

        ($this->headerSetter())($ro, 60, null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('public, max-age=60', $ro->headers['cache-control']);
    }

    public function testSetCacheControlMaxAgeDoesNotDuplicateAnExistingLowercaseMaxAge(): void
    {
        $ro = new None();
        $ro->headers['cache-control'] = 'max-age=30';

        ($this->headerSetter())($ro, 60, null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('max-age=30', $ro->headers['cache-control']);
    }

    public function testSetCacheControlMaxAgeDoesNotAppendALifetimeToNoStore(): void
    {
        // A declared #[HttpCache(noStore: true)] is still stored server-side, but the client
        // header must not pair no-store with a lifetime (#243).
        $ro = new CacheableResponseNoStore();
        $ro->headers[Header::CACHE_CONTROL] = 'no-store';

        ($this->headerSetter())($ro, 31536000, null);

        $this->assertSame('no-store', $ro->headers[Header::CACHE_CONTROL]);
    }
}
