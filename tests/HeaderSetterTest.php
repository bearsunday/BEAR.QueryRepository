<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function array_intersect_ukey;
use function array_keys;
use function strcasecmp;

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

    public function testTheDeclaredCacheControlReplacesTheResponsesOwnUnderAnyCasing(): void
    {
        $ro = new None();
        $ro->headers['cache-control'] = 'public';

        $this->headerSetter()->page($ro, Storability::Shared, null, 'max-age=60', null);

        $this->assertSame(['cache-control' => 'max-age=60'], array_intersect_ukey($ro->headers, [Header::CACHE_CONTROL => true], strcasecmp(...)));
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

    public function testSharedPageGetsACdnLifetimeAfterItsKeysAreRead(): void
    {
        $ro = new None();
        $ro->headers[Header::SURROGATE_KEY] = 'a b';

        $keys = $this->headerSetter()->page($ro, Storability::Shared, 10, null, null);

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

    /** @return array<string, array{string, string}> */
    public static function declaredPageCacheControlProvider(): array
    {
        return [
            'lifetime' => ['max-age=60', 'max-age=60'],
            'no lifetime' => ['public', 'public, max-age=0'],
        ];
    }

    #[DataProvider('declaredPageCacheControlProvider')]
    public function testDeclaredCacheControlReplacesTheOneThePageSet(string $declared, string $expected): void
    {
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'max-age=300';

        $this->headerSetter()->page($ro, Storability::Shared, 10, $declared, null);

        $this->assertSame($expected, $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testAnEmptyDeclaredCacheControlIsReplacedRatherThanAppendedTo(): void
    {
        $ro = new None();

        $this->headerSetter()->page($ro, Storability::Shared, 10, '', null);

        $this->assertSame('max-age=0', $ro->headers[Header::CACHE_CONTROL]);
    }

    public function testSharedPageThatIsNot200GetsNoCdnLifetime(): void
    {
        $ro = new None();
        $ro->code = 301;

        $this->headerSetter()->page($ro, Storability::Shared, 10, 'max-age=60', null);

        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertSame('max-age=0', $ro->headers[Header::CACHE_CONTROL], 'the declaration is for a 200, as HttpCacheInterceptor gives it');
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

        $keys = $this->headerSetter()->page($ro, Storability::Unstorable, 10, null, null);

        $this->assertSame(['a'], $keys->toArray());
        $this->assertSame([Header::CACHE_CONTROL => 'no-store'], $ro->headers);
    }
}
