<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Catalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_diff_key;
use function array_keys;
use function array_map;
use function crc32;
use function serialize;
use function trim;

use const PHP_EOL;

/**
 * What each cache kind stores, under which tags, and the headers it answers with
 *
 * The donut template is tagged without its holes; the page view carries them.
 */
class GoldenEntryTest extends TestCase
{
    private const ETAG = '<etag>';
    private const LAST_MODIFIED = '<last-modified>';

    protected function setUp(): void
    {
        Catalog::reset();
    }

    /**
     * @return array<string, array{
     *     string,
     *     array<string, list<string>>,
     *     array<string, list<string>>,
     *     array<string, string>,
     *     4?: bool,
     * }> uri, resource pool key => tags, URI tag of each entry holding an ETag => tags, response headers, rendered with Twig
     */
    public static function entryProvider(): array
    {
        return [
            '#[Cacheable] value' => [
                'page://self/dep/child-c',
                ['ro-_dep_child-c_' => ['_dep_child-c_', 'grp-_dep_child-c_']],
                ['_dep_child-c_' => ['grp-_dep_child-c_', '_dep_child-c_']],
                [Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED, Header::CACHE_CONTROL => 'max-age=0'],
            ],
            '#[Cacheable] value embedding a #[Cacheable] child' => [
                'page://self/dep/parent-a',
                [
                    'ro-_dep_child-c_' => ['_dep_child-c_', 'grp-_dep_child-c_'],
                    'ro-_dep_parent-a_' => ['_dep_parent-a_', 'grp-_dep_parent-a_', '_dep_child-c_', 'grp-_dep_child-c_'],
                ],
                [
                    '_dep_child-c_' => ['grp-_dep_child-c_', '_dep_child-c_'],
                    '_dep_parent-a_' => ['_dep_child-c_', 'grp-_dep_child-c_', 'grp-_dep_parent-a_', '_dep_parent-a_'],
                ],
                [Header::SURROGATE_KEY => '_dep_child-c_ grp-_dep_child-c_', Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED, Header::CACHE_CONTROL => 'max-age=0', 'Content-Type' => 'application/json'],
                false,
            ],
            '#[Cacheable] value with its own Surrogate-Key and an embedded child' => [
                'page://self/dep/tagged-parent',
                [
                    'ro-_dep_diamond-bottom_' => ['_dep_diamond-bottom_', 'grp-_dep_diamond-bottom_'],
                    'ro-_dep_tagged-parent_' => ['_dep_tagged-parent_', 'grp-_dep_tagged-parent_', 'shared-corpus', '_dep_diamond-bottom_', 'grp-_dep_diamond-bottom_'],
                ],
                [
                    '_dep_diamond-bottom_' => ['grp-_dep_diamond-bottom_', '_dep_diamond-bottom_'],
                    '_dep_tagged-parent_' => ['shared-corpus', '_dep_diamond-bottom_', 'grp-_dep_diamond-bottom_', 'grp-_dep_tagged-parent_', '_dep_tagged-parent_'],
                ],
                [Header::SURROGATE_KEY => 'shared-corpus _dep_diamond-bottom_ grp-_dep_diamond-bottom_', Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED, Header::CACHE_CONTROL => 'max-age=0', 'Content-Type' => 'application/json'],
                false,
            ],
            '#[Cacheable(type: view)]' => [
                'page://self/html/like',
                ['ro-_html_like_' => ['_html_like_', 'grp-_html_like_']],
                ['_html_like_' => ['grp-_html_like_', '_html_like_']],
                ['Content-Type' => 'text/html; charset=utf-8', Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED, Header::CACHE_CONTROL => 'max-age=0'],
            ],
            '#[Cacheable] with a default argument given' => [
                'app://self/optional-param?id=1&page=2',
                ['ro-_optional-param_id=1_page=2' => ['_optional-param_id=1_page=2', 'grp-_optional-param_id=1']],
                ['_optional-param_id=1_page=2' => ['grp-_optional-param_id=1', '_optional-param_id=1_page=2']],
                [Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED, Header::CACHE_CONTROL => 'max-age=0', 'Content-Type' => 'text/html; charset=utf-8'],
            ],
            '#[Cacheable] with #[HttpCache(etag:)]' => [
                'app://self/etag?updatedAt=x',
                ['ro-_etag_updatedAt=x' => ['_etag_updatedAt=x', 'grp-_etag_']],
                ['_etag_updatedAt=x' => ['grp-_etag_', '_etag_updatedAt=x']],
                [Header::CACHE_CONTROL => ', max-age=0', Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED],
            ],
            '#[Cacheable] with #[DependsOn]' => [
                'app://self/rows/ca?channelId=c1',
                ['ro-_rows_ca_channelId=c1' => ['_rows_ca_channelId=c1', 'grp-_rows_ca_channelId=c1', '_rows_taxonomy_ulid=c1', '_rows_article_ulid=a1', '_rows_taxonomy_ulid=s1', '_rows_article_ulid=a3']],
                ['_rows_ca_channelId=c1' => ['_rows_taxonomy_ulid=c1', '_rows_article_ulid=a1', '_rows_taxonomy_ulid=s1', '_rows_article_ulid=a3', 'grp-_rows_ca_channelId=c1', '_rows_ca_channelId=c1']],
                [Header::SURROGATE_KEY => '_rows_taxonomy_ulid=c1 _rows_article_ulid=a1 _rows_taxonomy_ulid=s1 _rows_article_ulid=a3', Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED, Header::CACHE_CONTROL => 'max-age=0'],
            ],
            '#[CacheableResponse] with a hole' => [
                'page://self/html/blog-posting',
                [
                    'donut-_html_blog-posting_' => ['_html_blog-posting_', 'grp-_html_blog-posting_', 'blog-posting-page'],
                    'ro-_html_blog-posting_' => ['_html_blog-posting_', 'grp-_html_blog-posting_', 'blog-posting-page', '_html_comment_', 'grp-_html_comment_', 'comment01'],
                    'ro-_html_comment_' => ['_html_comment_', 'grp-_html_comment_', 'comment01'],
                ],
                [
                    '_html_blog-posting_' => ['blog-posting-page', '_html_blog-posting_', 'grp-_html_blog-posting_', '_html_comment_', 'grp-_html_comment_', 'comment01'],
                    '_html_comment_' => ['comment01', 'grp-_html_comment_', '_html_comment_'],
                ],
                [
                    Header::SURROGATE_KEY => 'blog-posting-page _html_blog-posting_ grp-_html_blog-posting_ _html_comment_ grp-_html_comment_ comment01',
                    'Content-Type' => 'text/html; charset=utf-8',
                    Header::CDN_CACHE_CONTROL => 'max-age=10, stale-while-revalidate=10',
                    Header::ETAG => self::ETAG,
                    Header::LAST_MODIFIED => self::LAST_MODIFIED,
                    Header::CACHE_CONTROL => 'max-age=0',
                ],
            ],
            '#[CacheableResponse] with a material' => [
                'page://self/html/cacheable-response-with-material',
                [
                    'donut-_html_cacheable-response-with-material_' => ['_html_cacheable-response-with-material_', 'grp-_html_cacheable-response-with-material_', 'cacheable-response-with-material-page', '_html_mutable-comment_', 'grp-_html_mutable-comment_', 'mutable-comment'],
                    'ro-_html_cacheable-response-with-material_' => ['_html_cacheable-response-with-material_', 'grp-_html_cacheable-response-with-material_', 'cacheable-response-with-material-page', '_html_mutable-comment_', 'grp-_html_mutable-comment_', 'mutable-comment'],
                    'ro-_html_mutable-comment_' => ['_html_mutable-comment_', 'grp-_html_mutable-comment_', 'mutable-comment'],
                ],
                [
                    '_html_cacheable-response-with-material_' => ['cacheable-response-with-material-page', '_html_mutable-comment_', 'grp-_html_mutable-comment_', 'mutable-comment', '_html_cacheable-response-with-material_', 'grp-_html_cacheable-response-with-material_'],
                    '_html_mutable-comment_' => ['mutable-comment', 'grp-_html_mutable-comment_', '_html_mutable-comment_'],
                ],
                [
                    Header::SURROGATE_KEY => 'cacheable-response-with-material-page _html_mutable-comment_ grp-_html_mutable-comment_ mutable-comment _html_cacheable-response-with-material_ grp-_html_cacheable-response-with-material_',
                    'Content-Type' => 'text/html; charset=utf-8',
                    Header::CDN_CACHE_CONTROL => 'max-age=10, stale-while-revalidate=10',
                    Header::ETAG => self::ETAG,
                    Header::LAST_MODIFIED => self::LAST_MODIFIED,
                    Header::CACHE_CONTROL => 'max-age=0',
                ],
            ],
            '#[DonutCache] with a hole' => [
                'page://self/html/blog-posting-donut',
                [
                    'donut-_html_blog-posting-donut_' => ['_html_blog-posting-donut_', 'grp-_html_blog-posting-donut_'],
                    'ro-_html_comment_' => ['_html_comment_', 'grp-_html_comment_', 'comment01'],
                ],
                ['_html_comment_' => ['comment01', 'grp-_html_comment_', '_html_comment_']],
                ['Content-Type' => 'text/html; charset=utf-8', Header::SURROGATE_KEY => '_html_blog-posting-donut_ grp-_html_blog-posting-donut_ _html_comment_ grp-_html_comment_ comment01'],
            ],
            '#[DonutCache] with a material' => [
                'page://self/html/donut-cache-with-material',
                [
                    'donut-_html_donut-cache-with-material_' => ['_html_donut-cache-with-material_', 'grp-_html_donut-cache-with-material_', '_html_comment_', 'grp-_html_comment_', 'comment01'],
                    'ro-_html_comment_' => ['_html_comment_', 'grp-_html_comment_', 'comment01'],
                ],
                ['_html_comment_' => ['comment01', 'grp-_html_comment_', '_html_comment_']],
                ['Content-Type' => 'text/html; charset=utf-8', Header::SURROGATE_KEY => '_html_comment_ grp-_html_comment_ comment01 _html_donut-cache-with-material_ grp-_html_donut-cache-with-material_'],
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $roEntries
     * @param array<string, list<string>> $etagEntries
     * @param array<string, string>       $headers
     */
    #[DataProvider('entryProvider')]
    public function testEntriesAndHeaders(string $uri, array $roEntries, array $etagEntries, array $headers, bool $twig = true): void
    {
        $app = new FakeObservedApp([], $twig);
        $ro = $app->resource->get($uri);

        $this->assertSame($roEntries, $app->roEntries(), 'resource pool');
        $this->assertEqualsCanonicalizing($etagEntries, array_map(static fn (array $entry): array => $entry[1], $app->etagEntries()), 'validated entries');
        $this->assertSame($headers, self::masked($ro->headers), 'headers');
        $this->assertEtagEntryIsTheResponseEtag($app, $ro);
    }

    /** A hit answers with the stored headers and an Age; a value entry gets its Content-Type when the hit renders */
    public function testAHitAnswersWithTheStoredHeaders(): void
    {
        $cases = [
            'page://self/dep/parent-a' => [false, ['Content-Type' => 'application/json']],
            'page://self/html/like' => [true, []],
            'page://self/html/blog-posting' => [true, []],
        ];
        foreach ($cases as $uri => [$twig, $ofRendering]) {
            $app = new FakeObservedApp([], $twig);
            $miss = $app->resource->get($uri);
            $hit = $app->resource->get($uri);

            $this->assertMatchesRegularExpression('/^\d+$/', $hit->headers['Age'] ?? '');
            $hit->headers['Age'] = '<age>';
            $stored = [...array_diff_key($miss->headers, $ofRendering), 'Age' => '<age>'];

            $this->assertSame($stored, $hit->headers, $uri);
            $this->assertSame($miss->view, (string) $hit, $uri);
            $this->assertSame([...$stored, ...$ofRendering], $hit->headers, $uri);
        }
    }

    /** A page recomposed after its hole is purged is stored, tagged and validated as it was first */
    public function testARecomposedPageIsStoredAsItWasFirst(): void
    {
        $app = new FakeObservedApp();
        $storage = $app->injector->getInstance(ResourceStorageInterface::class);
        $first = $app->resource->get('page://self/html/blog-posting');
        $stored = [$app->roEntries(), $app->etagEntries()];

        $storage->invalidateTags(['_html_comment_']);
        $this->assertSame(['donut-_html_blog-posting_' => ['_html_blog-posting_', 'grp-_html_blog-posting_', 'blog-posting-page']], $app->roEntries(), 'only the template survives');
        $this->assertSame([], $app->etagEntries());

        $recomposed = $app->resource->get('page://self/html/blog-posting');
        $this->assertSame($stored, [$app->roEntries(), $app->etagEntries()]);
        $this->assertSame($first->headers[Header::ETAG], $recomposed->headers[Header::ETAG]);
        $this->assertSame($first->view, $recomposed->view);
        $this->assertSame([
            Header::SURROGATE_KEY => 'blog-posting-page _html_blog-posting_ grp-_html_blog-posting_ _html_comment_ grp-_html_comment_ comment01',
            'Content-Type' => 'text/html; charset=utf-8',
            Header::ETAG => self::ETAG,
            Header::LAST_MODIFIED => self::LAST_MODIFIED,
            Header::CACHE_CONTROL => 'max-age=0',
            Header::CDN_CACHE_CONTROL => 'max-age=10, stale-while-revalidate=10',
        ], self::masked($recomposed->headers), 'the same headers, CDN-Cache-Control last');

        $storage->invalidateTags(['_html_comment_']);
        $this->assertSame(['donut-_html_blog-posting_'], array_keys($app->roEntries()), 'a second purge reaches the recomposed view');
        $this->assertSame([], $app->etagEntries());
    }

    public function testADonutCachePageIsRecomposedWithTheSameHeadersAndNothingMoreStored(): void
    {
        $app = new FakeObservedApp();
        $first = $app->resource->get('page://self/html/blog-posting-donut');
        $stored = [$app->roEntries(), $app->etagEntries()];

        $second = $app->resource->get('page://self/html/blog-posting-donut');

        $this->assertSame($stored, [$app->roEntries(), $app->etagEntries()]);
        $this->assertSame($first->headers, $second->headers);
        $this->assertSame($first->view, $second->view);
    }

    public function testViews(): void
    {
        $app = new FakeObservedApp();

        $this->assertSame("like0\n", $app->resource->get('page://self/html/like')->view);
        $this->assertSame('blog-posting:1<comment>comment01</comment>', $app->resource->get('page://self/html/blog-posting')->view);
    }

    /** crc32 of the woven class, the class again, the serialized view or else the body, and the URI */
    public function testTheEtagOfAValueHashesItsBody(): void
    {
        $app = new FakeObservedApp();
        $ro = $app->resource->get('page://self/dep/child-c');

        $this->assertSame(self::etag($ro, $ro::class . serialize(['child-c' => 1])), $ro->headers[Header::ETAG]);
    }

    /** In a body that stands for the response, an embedded child counts as an object of its class, code, and view */
    public function testTheEtagOfAValueWithNoViewHashesItsEmbeddedChildAsAnObject(): void
    {
        $app = new FakeObservedApp();
        $childClass = $app->resource->newInstance('app://self/optional-param')::class;
        $ro = $app->resource->get('page://self/variant-parent');
        $body = (array) $ro->body;
        $child = $body['child'];
        $this->assertInstanceOf(AbstractRequest::class, $child);
        $childView = (string) $child;
        $state = ['child' => (object) [$childClass, 200, $childView], 'time' => $body['time']];

        $this->assertNull($ro->view, 'no template for this page');
        $this->assertStringStartsWith('1:2:', $childView);
        $this->assertSame(self::etag($ro, $ro::class . serialize($state)), $ro->headers[Header::ETAG]);
    }

    /** The ETag of a value that renders is recomputed from the string the miss sends */
    public function testTheEtagOfAValueHashesTheStringTheMissSends(): void
    {
        $app = new FakeObservedApp([], false);
        $ro = $app->resource->get('page://self/dep/parent-a');
        $sent = (string) $ro;

        $this->assertSame("{\n    \"parent-a\": 1,\n    \"child\": {\n        \"child-c\": 1\n    }\n}" . PHP_EOL, $sent);
        $this->assertSame(self::etag($ro, $ro::class . serialize($sent)), $ro->headers[Header::ETAG]);
    }

    public function testTheSameValueRenderedDifferentlyHasADifferentEtag(): void
    {
        $uri = 'page://self/html/plain-cacheable-child';
        $json = (new FakeObservedApp([], false))->resource->get($uri);
        $html = (new FakeObservedApp())->resource->get($uri);

        $this->assertSame($json->body, $html->body);
        $this->assertSame('plain-cacheable-child:plain-cacheable-child-value', $html->view);
        $this->assertNotSame($json->view, $html->view);
        $this->assertNotSame($json->headers[Header::ETAG], $html->headers[Header::ETAG]);
        $this->assertSame(self::etag($html, $html::class . serialize($html->view)), $html->headers[Header::ETAG]);
    }

    public function testTheEtagOfAViewHashesTheView(): void
    {
        $app = new FakeObservedApp();
        foreach (['page://self/html/like' => "like0\n", 'page://self/html/blog-posting' => 'blog-posting:1<comment>comment01</comment>'] as $uri => $view) {
            $ro = $app->resource->get($uri);

            $this->assertSame(self::etag($ro, $ro::class . serialize($view)), $ro->headers[Header::ETAG], $uri);
        }
    }

    public function testThePartialEtagHashesTheNamedFieldsOnly(): void
    {
        $app = new FakeObservedApp();
        $ro = $app->resource->get('app://self/etag?updatedAt=x');

        $this->assertSame(self::etag($ro, 'x'), $ro->headers[Header::ETAG]);
    }

    private static function etag(ResourceObject $ro, string $state): string
    {
        $k = (new CacheIdentityFactory())->locator($ro->uri);

        return '"' . $k . '.' . crc32($ro::class . $state . (string) $ro->uri) . '"';
    }

    private function assertEtagEntryIsTheResponseEtag(FakeObservedApp $app, ResourceObject $ro): void
    {
        if (! isset($ro->headers[Header::ETAG])) {
            return;
        }

        $uriTag = (new UriTag())($ro->uri);
        $this->assertSame(trim($ro->headers[Header::ETAG], '"'), $app->etagEntries()[$uriTag][0] ?? null);
        $this->assertMatchesRegularExpression('/^"[\w-]{22}\.\d+"$/', $ro->headers[Header::ETAG]);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $ro->headers[Header::LAST_MODIFIED] ?? '');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function masked(array $headers): array
    {
        foreach ([Header::ETAG => self::ETAG, Header::LAST_MODIFIED => self::LAST_MODIFIED] as $name => $mask) {
            if (isset($headers[$name])) {
                $headers[$name] = $mask;
            }
        }

        return $headers;
    }
}
