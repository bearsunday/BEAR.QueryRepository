<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;

class HeaderSetterTest extends TestCase
{
    private function headerSetter(UncacheableMarkInterface $mark): HeaderSetter
    {
        return new HeaderSetter(new EtagSetter(), new UnstorableGate($mark, new DeclaredCacheControl()));
    }

    public function testStripHeadersMatchesALowercaseCdnCacheControlHeaderName(): void
    {
        // $ro->headers is a plain PHP array, not an HTTP-aware collection (RFC 7230 §3.2):
        // an application that wrote 'cdn-cache-control' (lowercase) must not survive an
        // unstorable refusal just because it does not match the exact canonical casing.
        $mark = new UncacheableMark();
        $mark->mark('csrf-token-issued');
        $ro = new None();
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        ($this->headerSetter($mark))($ro, null, null);

        $this->assertArrayNotHasKey('cdn-cache-control', $ro->headers);
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testSetCacheControlMaxAgeFindsAnExistingLowercaseCacheControlHeaderName(): void
    {
        // An app-set 'cache-control' (lowercase) must be recognized as the SAME header an
        // exact-key isset() would miss - otherwise this setter adds a second, canonically-
        // cased Cache-Control header alongside it instead of appending to the existing one.
        $mark = new UncacheableMark();
        $ro = new None();
        $ro->headers['cache-control'] = 'public';

        ($this->headerSetter($mark))($ro, 60, null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('public, max-age=60', $ro->headers['cache-control']);
    }

    public function testSetCacheControlMaxAgeDoesNotDuplicateAnExistingLowercaseMaxAge(): void
    {
        $mark = new UncacheableMark();
        $ro = new None();
        $ro->headers['cache-control'] = 'max-age=30';

        ($this->headerSetter($mark))($ro, 60, null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('max-age=30', $ro->headers['cache-control']);
    }
}
