<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Override;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function hash;
use function rtrim;
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

        $this->assertSame('ro-' . self::k('custom-mobilejamobile'), (new CacheIdentityFactory($context))->storageKey('custom-', 'ro-'));
        $this->assertSame('donut-' . self::k('custom-mobilejamobile'), (new CacheIdentityFactory($context))->storageKey('custom-', 'donut-'));
        $this->assertSame('ro-' . self::k('custom-'), (new CacheIdentityFactory(new FakeServerContext([])))->storageKey('custom-', 'ro-'));
        $this->assertSame('ro-' . self::k('custom-'), (new CacheIdentityFactory(new FakeServerContext(['X_VARY' => ''])))->storageKey('custom-', 'ro-'));
    }

    /** The locator K of an exact tag followed by its vary values */
    public static function k(string $material): string
    {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $material, true), 0, 16)), '+/', '-_'), '=');
    }
}
