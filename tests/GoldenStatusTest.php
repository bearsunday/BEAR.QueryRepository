<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;

/** What is stored, and with which headers, for a response that is not 200 */
class GoldenStatusTest extends TestCase
{
    public function testAPutOfAnyCodeIsStoredWithoutAnEtag(): void
    {
        $app = new FakeObservedApp();
        $ro = new None();
        $ro->uri = new Uri('page://self/none');
        $ro->onGet();
        $ro->code = 202;

        $this->assertTrue($app->injector->getInstance(QueryRepositoryInterface::class)->put($ro));
        $this->assertSame(['ro-_none_' => ['_none_', 'grp-_none_']], $app->roEntries());
        $this->assertSame([], $app->etagEntries());
        $this->assertSame([], $ro->headers);
    }

    public function testCacheableStoresNothingForA203(): void
    {
        $app = new FakeObservedApp();
        $ro = $app->resource->get('app://self/code');

        $this->assertSame(203, $ro->code);
        $this->assertSame([], $app->roEntries());
        $this->assertSame([], $app->etagEntries());
        $this->assertSame([], $ro->headers);
    }

    /**
     * @return array<string, array{string, int, array<string, list<string>>, list<string>, array<string, string>}>
     *     uri, code, resource pool key => tags, URI tags of the entries holding an ETag, response headers
     */
    public static function donutProvider(): array
    {
        return [
            '#[CacheableResponse] 202' => [
                'page://self/html/accepted-page',
                202,
                [
                    'donut-_html_accepted-page_' => ['_html_accepted-page_', 'grp-_html_accepted-page_'],
                    'ro-_html_accepted-page_' => ['_html_accepted-page_', 'grp-_html_accepted-page_', '_html_comment_', 'grp-_html_comment_', 'comment01'],
                    'ro-_html_comment_' => ['_html_comment_', 'grp-_html_comment_', 'comment01'],
                ],
                ['_html_comment_'],
                ['Content-Type' => 'text/html; charset=utf-8', Header::SURROGATE_KEY => '_html_accepted-page_ grp-_html_accepted-page_ _html_comment_ grp-_html_comment_ comment01', Header::CACHE_CONTROL => 'max-age=0'],
            ],
            '#[CacheableResponse] 204' => [
                'page://self/html/no-content-page',
                204,
                [
                    'donut-_html_no-content-page_' => ['_html_no-content-page_', 'grp-_html_no-content-page_'],
                    'ro-_html_no-content-page_' => ['_html_no-content-page_', 'grp-_html_no-content-page_'],
                ],
                [],
                ['Content-Type' => 'text/html; charset=utf-8', Header::SURROGATE_KEY => '_html_no-content-page_ grp-_html_no-content-page_', Header::CACHE_CONTROL => 'max-age=0'],
            ],
            '#[CacheableResponse] 301' => [
                'page://self/html/redirect-page',
                301,
                [
                    'donut-_html_redirect-page_' => ['_html_redirect-page_', 'grp-_html_redirect-page_'],
                    'ro-_html_redirect-page_' => ['_html_redirect-page_', 'grp-_html_redirect-page_'],
                ],
                [],
                ['Location' => '/html/blog-posting?id=0', 'Content-Type' => 'text/html; charset=utf-8', Header::SURROGATE_KEY => '_html_redirect-page_ grp-_html_redirect-page_', Header::CACHE_CONTROL => 'max-age=0'],
            ],
            '#[CacheableResponse] 400' => ['page://self/html/error-page', 400, [], [], []],
            '#[DonutCache] 301' => [
                'page://self/html/redirect-donut',
                301,
                ['donut-_html_redirect-donut_' => ['_html_redirect-donut_', 'grp-_html_redirect-donut_']],
                [],
                ['Location' => '/html/blog-posting?id=0', 'Content-Type' => 'text/html; charset=utf-8', Header::SURROGATE_KEY => '_html_redirect-donut_ grp-_html_redirect-donut_'],
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $roEntries
     * @param list<string>                $etagUriTags
     * @param array<string, string>       $headers
     */
    #[DataProvider('donutProvider')]
    public function testDonut(string $uri, int $code, array $roEntries, array $etagUriTags, array $headers): void
    {
        $app = new FakeObservedApp();
        $ro = $app->resource->get($uri);

        $this->assertSame($code, $ro->code);
        $this->assertSame($roEntries, $app->roEntries());
        $this->assertSame($etagUriTags, array_keys($app->etagEntries()));
        $this->assertSame($headers, $ro->headers);
    }
}
