<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

class CacheTagsTest extends TestCase
{
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
    }

    public function testTagsReadHeaderOrExplicitOverride(): void
    {
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('app://self/child');
        $ro->headers[Header::SURROGATE_KEY] = ' data  data ';
        $uriTag = new UriTag(new CacheIdentityFactory(new GlobalServerContext()));
        $baseUriTag = new BaseUriTag($uriTag, $this->injector->getInstance(CacheIdentityFactory::class));
        $cacheTags = new CacheTags($uriTag, $baseUriTag, $this->injector->getInstance(CacheIdentityFactory::class));

        $this->assertSame(['_child_', 'grp-_child_', 'data'], $cacheTags->tags($ro)->toArray());
        $this->assertSame(['_child_', 'grp-_child_'], $cacheTags->tags($ro, new TagSet())->toArray());
    }

    /** @return array<string, array{string|null, string}> */
    public static function appKeyProvider(): array
    {
        return ['without an application key' => [null, 'a b'], 'with one' => ['app-key', 'app-key a b']];
    }

    /** The application's key comes first; without one, the header is still written without duplicate or empty keys */
    #[DataProvider('appKeyProvider')]
    public function testTagHeaderPutsTheAppKeyFirstAndNormalisesTheHeader(string|null $appKey, string $expected): void
    {
        $identityFactory = $this->injector->getInstance(CacheIdentityFactory::class);
        $uriTag = new UriTag(new CacheIdentityFactory(new GlobalServerContext()));
        $ro = new class extends ResourceObject {
        };
        $ro->headers[Header::SURROGATE_KEY] = 'a  a b';
        (new CacheTags($uriTag, new BaseUriTag($uriTag, $identityFactory), $identityFactory, $appKey))->tagHeader($ro);

        $this->assertSame($expected, $ro->headers[Header::SURROGATE_KEY]);
    }
}
