<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Override;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function base64_encode;
use function hash;
use function rtrim;
use function str_repeat;
use function strtr;
use function substr;

final class CacheIdentityFactoryTest extends TestCase
{
    public function testExactTagNormalizesStringablesWithoutChangingTheQuery(): void
    {
        $uri = new Uri('app://self/item');
        $query = ['z' => [new FakeStringableId('a/b')], 'a' => new FakeStringableId('x y')];
        $uri->query = $query;

        $this->assertSame('_item_a=x+y_z%5B0%5D=a%2Fb', (new CacheIdentityFactory())->exactTag($uri));
        $this->assertSame($query, $uri->query);
    }

    public function testSnakeName(): void
    {
        $factory = new CacheIdentityFactory();

        $this->assertSame('user_id', $factory->snakeName('userId'));
        $this->assertSame('u_r_l_value', $factory->snakeName('URLValue'));
        $this->assertSame('token', $factory->snakeName('token'));
    }

    public function testGroupTagKeepsTheSuppliedRequiredSpellingAndCustomFormatter(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(string $userId, string $token, int $page = 1): static
            {
                unset($userId, $token, $page);

                return $this;
            }
        };
        $ro->uri = new Uri('app://self/item?user_id=snake&userId=camel&page=2&extra=x');
        $uriTag = new class implements UriTagInterface {
            #[Override]
            public function __invoke(AbstractUri $uri): string
            {
                return 'custom-' . (new CacheIdentityFactory())->exactTag($uri);
            }

            #[Override]
            public function fromAssoc(string $uriTemplate, array $assoc): string
            {
                return (new UriTag())->fromAssoc($uriTemplate, $assoc);
            }
        };

        $this->assertSame('grp-custom-_item_userId=camel', (new BaseUriTag($uriTag))($ro));
        $this->assertSame(['user_id' => 'snake', 'userId' => 'camel', 'page' => '2', 'extra' => 'x'], $ro->uri->query);
        $ro->uri = new Uri('app://self/item?user_id=snake&page=2');
        $this->assertSame('grp-custom-_item_user_id=snake', (new BaseUriTag($uriTag))($ro));
    }

    public function testGroupTagWithoutOnGetKeepsTheWholeQuery(): void
    {
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('page://self/item?page=2');

        $this->assertSame('grp-_item_page=2', (new BaseUriTag(new UriTag()))($ro));
    }

    public function testStorageKeyReadsVaryInDeclaredOrderWithRepeatsAndMissingValues(): void
    {
        $context = new FakeServerContext(['X_VARY' => 'device, , missing, lang, device', 'X_LANG' => 'ja', 'X_DEVICE' => 'mobile']);

        $this->assertSame('ro-K.' . self::k('6:mobile2:ja6:mobile'), (new CacheIdentityFactory($context))->storageKey('K', 'ro-'));
        $this->assertSame('donut-K.' . self::k('6:mobile2:ja6:mobile'), (new CacheIdentityFactory($context))->storageKey('K', 'donut-'));
        $this->assertSame('ro-K', (new CacheIdentityFactory(new FakeServerContext([])))->storageKey('K', 'ro-'));
        $this->assertSame('ro-K', (new CacheIdentityFactory(new FakeServerContext(['X_VARY' => ''])))->storageKey('K', 'ro-'));
    }

    public function testVaryValuesAreToldApartWhereverTheyAreSplit(): void
    {
        $key = static fn (string $lang, string $device): string => (new CacheIdentityFactory(
            new FakeServerContext(['X_VARY' => 'lang,device', 'X_LANG' => $lang, 'X_DEVICE' => $device]),
        ))->storageKey('K', 'ro-');

        $this->assertNotSame($key('ja', 'mobile'), $key('jam', 'obile'));
    }

    public function testLocatorKeepsTheSchemeHostAndSeparatorsAndIgnoresVary(): void
    {
        $factory = new CacheIdentityFactory(new FakeServerContext(['X_VARY' => 'lang', 'X_LANG' => 'ja']));
        $uris = ['app://self/a/b', 'app://self/a_b', 'page://self/a/b', 'app://other/a/b', 'app://self/a?b=', 'app://self/a/b?x=1&y=2'];
        $locators = array_map(static fn (string $uri): string => $factory->locator(new Uri($uri)), $uris);

        $this->assertSame($locators, array_unique($locators));
        $this->assertSame(self::k('app://self/a/b?'), $locators[0]);
        $this->assertSame(self::k('app://self/a/b?x=1&y=2'), $factory->locator(new Uri('app://self/a/b?y=2&x=1')));
        $this->assertSame((string) (new UriTag())(new Uri($uris[0])), (string) (new UriTag())(new Uri($uris[2])), 'while the tags stay shared');
    }

    public function testLocatorOfReadsOnlyTheShapeOfALocator(): void
    {
        $factory = new CacheIdentityFactory();
        $k = $factory->locator(new Uri('app://self/a'));

        $this->assertSame($k, $factory->locatorOf($k . '.123'));
        $this->assertSame($k, $factory->locatorOf($k . '.'));
        foreach ([$k, substr($k, 1) . '.1', $k . 'a.1', 'ro-' . $k . '.1', "\n" . $k . '.1', '日本.語', '.', '', str_repeat('b', 1000) . '.x'] as $value) {
            $this->assertNull($factory->locatorOf($value), $value);
        }
    }

    /** The 22 base64url characters a locator or a vary suffix is made of */
    public static function k(string $material): string
    {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $material, true), 0, 16)), '+/', '-_'), '=');
    }
}
