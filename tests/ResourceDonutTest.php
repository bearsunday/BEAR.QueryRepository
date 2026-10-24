<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Exception\ParameterException;
use BEAR\Resource\Method;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use RuntimeException;

use function dirname;
use function htmlspecialchars;
use function http_build_query;

use const ENT_QUOTES;

class ResourceDonutTest extends TestCase
{
    private ResourceInterface $resource;
    private UncacheableMarkInterface $mark;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->mark = new UncacheableMark();

        parent::setUp();
    }

    public function testRefreshResolvesTwoPlaceholdersOnTheSameLine(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>[le:page://self/html/comment][le:page://self/html/like]</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->mark);

        $this->assertStringContainsString('comment01', (string) $refreshed->view);
        $this->assertStringContainsString('like0', (string) $refreshed->view);
        $this->assertStringNotContainsString('[le:', (string) $refreshed->view);
    }

    public function testRefreshResolvesPlaceholdersOnSeparateLines(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut("<p>[le:page://self/html/comment]</p>\n<p>[le:page://self/html/like]</p>", [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->mark);

        $this->assertStringContainsString('comment01', (string) $refreshed->view);
        $this->assertStringContainsString('like0', (string) $refreshed->view);
        $this->assertStringNotContainsString('[le:', (string) $refreshed->view);
    }

    /**
     * A query value a legacy entry's placeholder needed http_build_query() to protect: an
     * encoded space ('+'), an encoded slash ('%2F'), a non-ASCII character (its own
     * multi-byte '%XX' sequence), and reserved query-string characters within the value
     * itself ('&', '=') - the case that corrupts silently rather than fatally: blanket-
     * decoding it before BEAR\Resource\Uri parses it lets parse_str() re-split the
     * now-literal '&'/'=' into extra, unrelated params instead of leaving them part of
     * the value.
     *
     * @return array<string, array{0: string}>
     */
    public static function legacyQueryValueProvider(): array
    {
        return [
            'encoded space (+)' => ['a b'],
            'encoded slash (%2F)' => ['a/b'],
            'non-ASCII' => ['日本語'],
            'reserved characters (&, =)' => ['a&b=c'],
        ];
    }

    /**
     * A legacy `[le:URI]` placeholder - written before this version introduced `le2` -
     * must still be used verbatim, not rawurldecode()'d: its query value was already
     * percent-encoded once (by http_build_query() when the placeholder was first built),
     * and BEAR\Resource\Uri decodes it exactly once when it parses the re-fetched URI. An
     * extra decode here would strip that encoding before parsing ever sees it, corrupting
     * the value (or, for a raw non-ASCII byte, failing BEAR\Resource\Uri's own URL
     * validation outright) instead of leaving an untouched legacy entry working exactly as
     * it always did.
     */
    #[DataProvider('legacyQueryValueProvider')]
    public function testRefreshDoesNotDoubleDecodeALegacyPlaceholdersQueryValue(string $value): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $query = http_build_query(['token' => $value]);
        $donut = new ResourceDonut('<p>[le:app://self/query-hole?' . $query . ']</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->mark);

        $this->assertSame('<p>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</p>', (string) $refreshed->view, 'the legacy placeholder\'s URI must reach BEAR\Resource\Uri exactly as stored, not pre-decoded');
    }

    /**
     * The new `le2` form (ResourceDonut::placeholder()) round-trips the same values a
     * legacy entry carries, through its own rawurlencode()/rawurldecode() pair.
     */
    #[DataProvider('legacyQueryValueProvider')]
    public function testRefreshRoundTripsTheNewFormPlaceholdersQueryValue(string $value): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $placeholder = ResourceDonut::placeholder('app://self/query-hole?' . http_build_query(['token' => $value]));
        $donut = new ResourceDonut('<p>' . $placeholder . '</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->mark);

        $this->assertSame('<p>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</p>', (string) $refreshed->view);
    }

    /**
     * A legacy `[le:URI]` placeholder for a hole with two OPTIONAL params and the same
     * #232 `&`-to-`amp;` corruption: BEAR\Resource\Uri splits the query on the literal `&`
     * left behind, so the second `token=b` becomes the unrelated key `amp;token`, and the
     * first `token=a` silently wins - no required param is missing, so nothing throws, and
     * the hole quietly recomposes with the wrong (first, not last) value. This is the
     * "quieter" failure mode #232 describes for optional params, and it must stay exactly
     * this wrong, not become something else, now that refresh() treats `le2` and `le`
     * placeholders differently.
     */
    public function testRefreshOfALegacyDuplicateKeyPlaceholderStaysExactlyAsWrongAsBeforeThisFix(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>[le:app://self/query-hole?token=a&amp;token=b]</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->mark);

        $this->assertSame('<p>a</p>', (string) $refreshed->view, 'the first token "wins" exactly as it already did - not "b", and not an exception');
    }

    /**
     * A legacy `[le:URI]` placeholder for a hole with two REQUIRED query params, corrupted
     * the same way, stays exactly as broken as it already was: still a ParameterException,
     * the same failure a pre-#232-fix refresh() threw - not this fix's rawurldecode()
     * turning it into a different, new failure.
     */
    public function testRefreshOfALegacyMultiParamPlaceholderFailsTheSameWayAsBeforeThisFix(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>[le:app://self/multi-param-query-hole?id=1&amp;tag=2]</p>', [], null, false);

        $this->expectException(ParameterException::class);
        $donut->refresh($this->resource, $ro, $this->mark);
    }

    /**
     * A re-fetch that throws must still leave the hole's scope closed, the same way
     * `finally` guarantees `leaveHole()` runs: an exception from `$resource->get($uri)`
     * (a pool outage, a broken onGet()) must not leave `$uri` stuck on the mark's owner
     * stack for the rest of the request - which would misattribute a later, unrelated
     * mark() to this hole's scope instead of TOP_LEVEL.
     */
    public function testRefreshLeavesTheHoleScopeEvenWhenTheReFetchThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $uri = 'app://self/query-hole?token=x';
        $donut = new ResourceDonut('<p>' . ResourceDonut::placeholder($uri) . '</p>', [], null, false);
        $throwingResource = new class implements ResourceInterface {
            public function newInstance($uri): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function object(ResourceObject $ro): RequestInterface
            {
                throw new RuntimeException('not used');
            }

            public function uri($uri): RequestInterface
            {
                throw new RuntimeException('not used');
            }

            public function newRequest(Method $method, string $uri, array $query = []): RequestInterface
            {
                throw new RuntimeException('not used');
            }

            public function crawl(string $uri, string $linkKey, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function href(string $rel, array $query = [], ResourceObject|null $ro = null): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function get(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('pool outage');
            }

            public function post(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function put(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function patch(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function delete(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function head(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }

            public function options(string $uri, array $query = []): ResourceObject
            {
                throw new RuntimeException('not used');
            }
        };

        try {
            $donut->refresh($throwingResource, $ro, $this->mark);
            $this->fail('expected the re-fetch to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('pool outage', $e->getMessage());
        }

        // A balanced stack would make this leaveHole() a no-op (the uri is not on it), so
        // the mark() just above stays filed under TOP_LEVEL and is still visible here. A
        // leaked entry instead puts $uri back on top: mark() files under $uri, leaveHole()
        // pops exactly that entry, and the current scope reverts to TOP_LEVEL - which this
        // mark() never touched - making the check below read false instead.
        $this->mark->mark('after the throw');
        $this->mark->leaveHole($uri);

        $this->assertTrue(
            $this->mark->isMarkedForCurrentScope(),
            'a mark right after the aborted re-fetch must stay filed under TOP_LEVEL, not get displaced by a scope the throw left stuck on the stack',
        );
    }
}
