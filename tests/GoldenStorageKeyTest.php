<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * The URI tag and the pool keys built from it, as they are today
 *
 * Distinct URIs that share a tag (scheme dropped, `/` `?` `&` turned into `_`) share an entry.
 */
class GoldenStorageKeyTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function uriTagProvider(): array
    {
        return [
            'path' => ['app://self/a/b', [], '_a_b_'],
            'underscore in the path' => ['app://self/a_b', [], '_a_b_'],
            'page scheme' => ['page://self/a/b', [], '_a_b_'],
            'trailing slash' => ['app://self/a/b/', [], '_a_b__'],
            'query' => ['app://self/a/b?x=1&y=2', [], '_a_b_x=1_y=2'],
            'query in another order' => ['app://self/a/b?y=2&x=1', [], '_a_b_x=1_y=2'],
            'query in another order, page scheme' => ['page://self/a/b?y=2&x=1', [], '_a_b_x=1_y=2'],
            'underscore in a query name' => ['app://self/a?b_x=1', [], '_a_b_x=1'],
            'reserved characters in a value' => ['app://self/a', ['v' => 'a/b?c&d'], '_a_v=a%2Fb%3Fc%26d'],
            'space' => ['app://self/a', ['v' => 'a b'], '_a_v=a+b'],
            'multibyte' => ['app://self/a', ['v' => '日本'], '_a_v=%E6%97%A5%E6%9C%AC'],
            'list value' => ['app://self/a', ['ids' => ['x', 'y']], '_a_ids%5B0%5D=x_ids%5B1%5D=y'],
            'true and null' => ['app://self/a', ['flag' => true, 'n' => null], '_a_flag=1'],
            'empty string' => ['app://self/a', ['v' => ''], '_a_v='],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('uriTagProvider')]
    public function testUriTag(string $uri, array $query, string $expected): void
    {
        $uriObject = new Uri($uri);
        if ($query !== []) {
            $uriObject->query = $query;
        }

        $this->assertSame($expected, (new UriTag())($uriObject));
    }

    public function testUriTagsFromAssoc(): void
    {
        $this->assertSame('_item_id=1 _item_id=2', (new UriTag())->fromAssoc('app://self/item{?id}', [['id' => 1], ['id' => 2]]));
    }

    public function testUrisSharingATagShareAnEntry(): void
    {
        $app = new FakeObservedApp();
        $storage = $app->injector->getInstance(ResourceStorageInterface::class);
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('app://self/a/b?x=1');
        $ro->body = ['stored-as' => 'app://self/a/b?x=1'];
        $storage->saveValue($ro, 0);

        $this->assertSame(['ro-_a_b_x=1'], array_keys($app->roEntries()));
        foreach (['page://self/a/b?x=1', 'app://self/a_b?x=1', 'app://self/a?b_x=1'] as $other) {
            $this->assertSame(['stored-as' => 'app://self/a/b?x=1'], $storage->get(new Uri($other))?->body, $other);
        }
    }

    public function testVaryValuesAreAppendedToTheResourcePoolKeysOnly(): void
    {
        $app = new FakeObservedApp(['X_VARY' => 'lang, , device', 'X_LANG' => 'ja', 'X_DEVICE' => 'mobile']);
        $app->resource->get('page://self/dep/child-c');
        $app->resource->get('page://self/html/blog-posting');

        $this->assertSame([
            'donut-_html_blog-posting_jamobile',
            'ro-_dep_child-c_jamobile',
            'ro-_html_blog-posting_jamobile',
            'ro-_html_comment_jamobile',
        ], array_keys($app->roEntries()));
        $this->assertSame(['_dep_child-c_', '_html_blog-posting_', '_html_comment_'], array_keys($app->etagEntries()));
    }

    public function testAVaryNameWithNoValueAddsNothing(): void
    {
        $app = new FakeObservedApp(['X_VARY' => 'lang']);
        $app->resource->get('page://self/dep/child-c');

        $this->assertSame(['ro-_dep_child-c_'], array_keys($app->roEntries()));
    }
}
