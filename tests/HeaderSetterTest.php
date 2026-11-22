<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\Html\CacheableResponseNoStore;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function array_keys;

class HeaderSetterTest extends TestCase
{
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
    }

    private function headerSetter(): HeaderSetter
    {
        return $this->injector->getInstance(HeaderSetter::class);
    }

    public function testUnstorableStripMatchesALowercaseCdnCacheControlHeaderName(): void
    {
        // $ro->headers is a plain PHP array, not an HTTP-aware collection (RFC 7230 §3.2):
        // an application that wrote 'cdn-cache-control' (lowercase) must not survive an
        // unstorable refusal just because it does not match the exact canonical casing.
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        $this->headerSetter()->template($ro, Storability::Unstorable);

        $this->assertArrayNotHasKey('cdn-cache-control', $ro->headers);
        $this->assertSame('private', $ro->headers[Header::CACHE_CONTROL], "the developer's own header is kept exactly");
    }

    public function testUnstorableStripRemovesEveryCasingWhenTheSameHeaderIsSetTwice(): void
    {
        // $ro->headers is a plain array, so 'Cache-Control' and 'cache-control' can coexist
        // as two distinct keys. stripHeaders() must remove ALL of them, not just the first
        // match - a single-match lookup here would leave one behind, a lifetime header
        // surviving the unstorable refusal.
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';
        $ro->headers[Header::CDN_CACHE_CONTROL] = 'max-age=600';
        $ro->headers['cdn-cache-control'] = 'max-age=600';

        $this->headerSetter()->template($ro, Storability::Unstorable);

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

        $this->headerSetter()->entry($ro, Storability::Shared, 'max-age=60', null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('public, max-age=60', $ro->headers['cache-control']);
    }

    public function testSetCacheControlMaxAgeDoesNotDuplicateAnExistingLowercaseMaxAge(): void
    {
        $ro = new None();
        $ro->headers['cache-control'] = 'max-age=30';

        $this->headerSetter()->entry($ro, Storability::Shared, 'max-age=60', null);

        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $ro->headers, 'no second, canonically-cased header was added');
        $this->assertSame('max-age=30', $ro->headers['cache-control']);
    }

    public function testSetCacheControlMaxAgeDoesNotAppendALifetimeToNoStore(): void
    {
        // A declared #[HttpCache(noStore: true)] is still stored server-side, but the client
        // header must not pair no-store with a lifetime (#243).
        $ro = new CacheableResponseNoStore();
        $ro->headers[Header::CACHE_CONTROL] = 'no-store';

        $this->headerSetter()->entry($ro, Storability::Shared, 'max-age=31536000', null);

        $this->assertSame('no-store', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testSetCacheControlMaxAgeChecksEveryCacheControlKeyForNoStore(): void
    {
        // An app-set lowercase 'cache-control' can sit beside the declared 'Cache-Control:
        // no-store'; whichever key comes first, no lifetime may be added to either.
        $ro = new CacheableResponseNoStore();
        $ro->headers = ['cache-control' => 'public', Header::CACHE_CONTROL => 'no-store'];

        $this->headerSetter()->entry($ro, Storability::Shared, 'max-age=60', null);

        $this->assertSame('public', $ro->headers['cache-control']);
        $this->assertSame('no-store', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testSharedPageGetsACdnLifetimeAfterItsKeysAreRead(): void
    {
        $ro = new None();
        $ro->headers[Header::SURROGATE_KEY] = 'a b';

        $keys = $this->headerSetter()->page($ro, Storability::Shared, 10, null);

        $this->assertSame(['a', 'b'], $keys->toArray());
        $this->assertSame([
            Header::SURROGATE_KEY,
            Header::CDN_CACHE_CONTROL,
            Header::ETAG,
            Header::LAST_MODIFIED,
            Header::CACHE_CONTROL,
        ], array_keys($ro->headers), 'CDN lifetime, then validator, then client Cache-Control');
        $this->assertSame('max-age=0', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testSharedPageThatIsNot200GetsNoCdnLifetime(): void
    {
        $ro = new None();
        $ro->code = 301;

        $this->headerSetter()->page($ro, Storability::Shared, 10, null);

        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
    }

    public function testDeclaredPrivatePageKeepsItsPurgeKeysButNoCdnLifetime(): void
    {
        $ro = new None();
        $ro->headers = [Header::SURROGATE_KEY => 'a', Header::CDN_CACHE_CONTROL => 'max-age=600', Header::CACHE_CONTROL => 'private'];

        $this->headerSetter()->page($ro, Storability::DeclaredPrivate, 10, null);

        $this->assertSame('a', $ro->headers[Header::SURROGATE_KEY]);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);
        $this->assertSame('private, max-age=0', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testDeclaredPrivateEntryAndTemplateKeepTheirPurgeKeysButNoCdnLifetime(): void
    {
        $entry = new None();
        $entry->headers = [Header::SURROGATE_KEY => 'a', Header::CDN_CACHE_CONTROL => 'max-age=600', Header::CACHE_CONTROL => 'private'];
        $this->headerSetter()->entry($entry, Storability::DeclaredPrivate, 'max-age=10', null);
        $this->assertSame('a', $entry->headers[Header::SURROGATE_KEY]);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $entry->headers);
        $this->assertArrayHasKey(Header::ETAG, $entry->headers);
        $this->assertSame('private, max-age=10', $entry->headers[Header::CACHE_CONTROL]);

        $template = new None();
        $template->headers = [Header::SURROGATE_KEY => 'a', 'surrogate-control' => 'max-age=600', Header::CACHE_CONTROL => 'private'];
        $this->headerSetter()->template($template, Storability::DeclaredPrivate);
        $this->assertSame([Header::SURROGATE_KEY => 'a', Header::CACHE_CONTROL => 'private'], $template->headers);
    }

    public function testSharedTemplateIsLeftAsItIs(): void
    {
        $ro = new None();
        $ro->headers = [Header::SURROGATE_KEY => 'a', Header::CDN_CACHE_CONTROL => 'max-age=600'];
        $this->headerSetter()->template($ro, Storability::Shared);
        $this->assertSame([Header::SURROGATE_KEY => 'a', Header::CDN_CACHE_CONTROL => 'max-age=600'], $ro->headers);
    }

    public function testUnstorablePageGetsNoCdnFacingHeaderAndNoValidator(): void
    {
        $ro = new None();
        $ro->headers = [Header::SURROGATE_KEY => 'a', Header::CDN_CACHE_CONTROL => 'max-age=600', Header::CACHE_CONTROL => 'no-store'];

        $keys = $this->headerSetter()->page($ro, Storability::Unstorable, 10, null);

        $this->assertSame(['a'], $keys->toArray());
        $this->assertSame([Header::CACHE_CONTROL => 'no-store'], $ro->headers);
    }
}
