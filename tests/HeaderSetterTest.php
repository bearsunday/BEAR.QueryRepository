<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\None;
use LogicException;
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
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        ($this->headerSetter())($ro, null, null, unstorable: 'unstorable', wasMarked: true);

        $this->assertArrayNotHasKey('cdn-cache-control', $ro->headers);
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testStripHeadersRemovesEveryCasingWhenTheSameHeaderIsSetTwice(): void
    {
        // $ro->headers is a plain array, so 'Cache-Control' and 'cache-control' can coexist
        // as two distinct keys. stripHeaders() must remove ALL of them, not just the first
        // match - a single-match lookup here would leave one behind, a lifetime header
        // surviving the unstorable refusal.
        $ro = new None();
        $ro->headers[Header::CDN_CACHE_CONTROL] = 'max-age=600';
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        ($this->headerSetter())($ro, null, null, unstorable: 'unstorable', wasMarked: true);

        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertArrayNotHasKey('cdn-cache-control', $ro->headers);
    }

    public function testWasMarkedFalseKeepsTheResponsesOwnCacheControlInstead(): void
    {
        // The CodeRabbit-fixed half: a refusal caused only by the response's own
        // Cache-Control (no mark) must keep that header exactly, not replace it.
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';

        ($this->headerSetter())($ro, null, null, unstorable: 'unstorable', wasMarked: false);

        $this->assertSame('private', $ro->headers[Header::CACHE_CONTROL]);
        $this->assertArrayNotHasKey(Header::PRAGMA, $ro->headers);
    }

    public function testWasMarkedIsRequiredWheneverUnstorableIsGivenAndFailsLoudlyWhenOmitted(): void
    {
        // CodeRabbit review round 2: a nullable $wasMarked silently falling back to this
        // class's own isMarked() was exactly how the round-1 bug happened - no internal
        // predicate is left to fall back to; a forgotten caller must find out immediately,
        // not leak a wrong header in production.
        $ro = new None();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/requires \$wasMarked/');
        ($this->headerSetter())($ro, null, null, unstorable: 'unstorable');
    }

    public function testSetCacheControlMaxAgeFindsAnExistingLowercaseCacheControlHeaderName(): void
    {
        // An app-set 'cache-control' (lowercase) must be recognized as the SAME header an
        // exact-key isset() would miss - otherwise this setter adds a second, canonically-
        // cased Cache-Control header alongside it instead of appending to the existing one.
        $ro = new None();
        $ro->headers['cache-control'] = 'public';

        ($this->headerSetter())($ro, 60, null, null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('public, max-age=60', $ro->headers['cache-control']);
    }

    public function testSetCacheControlMaxAgeDoesNotDuplicateAnExistingLowercaseMaxAge(): void
    {
        $ro = new None();
        $ro->headers['cache-control'] = 'max-age=30';

        ($this->headerSetter())($ro, 60, null, null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('max-age=30', $ro->headers['cache-control']);
    }

    public function testFourPositionalArgumentsStayBcWithThe1170ReleasedSignatureTimeNotUnstorable(): void
    {
        // Released 1.17.0 has $time as the 4th (and last) positional parameter. This PR adds
        // $unstorable as a NEW, trailing, named-only-in-practice parameter - a caller still
        // passing a timestamp positionally as the 4th argument (the only way 1.17.0 allowed)
        // must keep getting $time, not bind it into the new $unstorable (string|null) slot,
        // which would TypeError on any real int timestamp.
        $ro = new None();

        ($this->headerSetter())($ro, null, null, 1700000000);

        // Last-Modified is gmdate(RFC7231, $time) (EtagSetter) - proof $time was actually
        // bound to the 4th parameter, not just that no TypeError was thrown.
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $ro->headers[Header::LAST_MODIFIED]);
        // $unstorable stayed at its default (null): the normal path ran, not strip-and-refuse.
        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers);
    }
}
