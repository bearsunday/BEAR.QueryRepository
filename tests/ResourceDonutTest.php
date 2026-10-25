<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreFailure;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Exception\ParameterException;
use BEAR\Resource\Method;
use BEAR\Resource\RenderInterface;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\Resource;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use ReflectionClassConstant;
use RuntimeException;

use function assert;
use function dirname;
use function error_clear_last;
use function error_get_last;
use function htmlspecialchars;
use function http_build_query;
use function is_string;
use function preg_match;

use const E_USER_WARNING;
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
     * the same way, fails the re-fetch with the same underlying cause as a pre-#232-fix
     * refresh() threw - still a ParameterException, not this fix's rawurldecode() turning it
     * into a different, new failure - except that cause now arrives wrapped in a
     * CacheStoreFailure instead of surfacing raw, the same conversion any re-fetch failure
     * gets so the interceptor can refuse the store instead of caching nothing.
     */
    public function testRefreshOfALegacyMultiParamPlaceholderFailsTheSameWayAsBeforeThisFix(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>[le:app://self/multi-param-query-hole?id=1&amp;tag=2]</p>', [], null, false);

        try {
            $donut->refresh($this->resource, $ro, $this->mark);
            $this->fail('expected the malformed legacy placeholder to fail the re-fetch');
        } catch (CacheStoreFailure $e) {
            $this->assertInstanceOf(ParameterException::class, $e->getPrevious());
        }
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

    /**
     * `BEAR\Resource\ResourceObject::__toString()` swallows a Throwable from a failed render
     * into an E_USER_WARNING and an empty string - a hole's own onGet() propagates here
     * uncaught (`DonutRequest::__toString()` has no catch of its own). Storing that empty
     * string as the donut template would serve an empty page to every later visitor (since
     * 1.17.0). This also pins BEAR.Resource's warning shape: if a future version changes it,
     * the guard's regex stops matching and this test fails instead of silently going back to
     * caching empty pages.
     */
    public function testCreateRefusesToStoreTheTemplateWhenAHoleThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/donut-cache-with-throwing-hole');
        $resource = $this->resource;
        assert($resource instanceof Resource);
        $throwingRequest = $resource->get->uri('app://self/throwing-hole');
        assert($throwingRequest instanceof AbstractRequest);
        $ro->body = ['secret' => $throwingRequest];

        error_clear_last();
        $this->expectException(CacheStoreFailure::class);
        try {
            ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false, $this->mark);
        } finally {
            // The handler returns false on a match, so PHP's own handling of the warning
            // still runs unsuppressed - error_get_last() is set by the engine regardless of
            // what any handler returns, so this proves the original warning was not lost.
            $last = error_get_last();
            $this->assertNotNull($last, 'the swallowed render warning must still surface, not be suppressed');
            $this->assertSame(E_USER_WARNING, $last['type']);
            $this->assertStringContainsString('the hole blew up mid-render', $last['message']);
        }
    }

    /**
     * Pins the guard's regex directly: anchored on `#0 `, the FIRST trace frame specifically,
     * not just any frame number - a `.*` message group that merely required SOME `)\n#N `
     * later in the string could still match past a deep-enough trace, swallowing part of it
     * into "message" instead of stopping at the true header boundary.
     */
    public function testSwallowedRenderWarningRegexMatchesPreciselyAndRejectsUnrelatedWarnings(): void
    {
        $pattern = (new ReflectionClassConstant(ResourceDonut::class, 'SWALLOWED_RENDER_WARNING'))->getValue();
        assert(is_string($pattern));

        $vendorShaped = "RuntimeException(the hole blew up mid-render)\n#0 /app/Foo.php(12): bar()\n#1 /app/Baz.php(34): qux()\n#2 {main}";
        $this->assertSame(1, preg_match($pattern, $vendorShaped, $match));
        $this->assertSame('RuntimeException', $match['class']);
        $this->assertSame('the hole blew up mid-render', $match['message']);

        foreach (['Undefined array key 3', 'Array to string conversion', 'Deprecated: foo() is deprecated'] as $unrelated) {
            $this->assertSame(0, preg_match($pattern, $unrelated), "must not misread: $unrelated");
        }
    }

    /**
     * The same swallow happens when the composing page's OWN template fails, outside any
     * hole - `(string) $ro` in create() renders the page itself, not only its holes.
     */
    public function testCreateRefusesToStoreTheTemplateWhenThePageTemplateItselfThrows(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(RenderInterface::class)->to(FakeThrowingRenderer::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $ro = $resource->newInstance('page://self/html/plain-donut-hole');
        $ro->body = ['value' => 'plain'];

        error_clear_last();
        $this->expectException(CacheStoreFailure::class);
        try {
            ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false, $mark);
        } finally {
            $last = error_get_last();
            $this->assertNotNull($last, 'the swallowed render warning must still surface, not be suppressed');
            $this->assertSame(E_USER_WARNING, $last['type']);
            $this->assertStringContainsString('template not found', $last['message']);
        }
    }

    /**
     * The guard must not refuse a page that renders without error - only the specific
     * swallowed-render warning shape triggers it.
     */
    public function testCreateStillStoresTheTemplateWhenNothingThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/plain-donut-hole');
        $ro->body = ['value' => 'plain'];

        $donut = ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false, $this->mark);
        $rendered = $donut->render($ro, new DonutRenderer());

        $this->assertStringContainsString('plain-donut-hole:plain', (string) $rendered->view);
    }

    /**
     * Unlike create()'s `(string) $ro`, refresh()'s per-hole `$ro->toString()` (and the
     * `$resource->get($uri)` re-fetch that runs the hole's onGet()) does not swallow a
     * Throwable - it propagates directly, so this is a plain try/catch, converted to the same
     * CacheStoreFailure create() raises, so both paths degrade through the same interceptor
     * code.
     */
    public function testRefreshRefusesToServeWhenAHoleThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $uri = 'app://self/throwing-hole';
        $donut = new ResourceDonut('<p>' . ResourceDonut::placeholder($uri) . '</p>', [], null, false);

        try {
            $donut->refresh($this->resource, $ro, $this->mark);
            $this->fail('expected the hole throw to be refused as a CacheStoreFailure');
        } catch (CacheStoreFailure $e) {
            $this->assertStringContainsString('the hole blew up mid-render', $e->getMessage());
        }

        // Same stack-balance check as testRefreshLeavesTheHoleScopeEvenWhenTheReFetchThrows:
        // the catch must not skip the finally that leaves the hole's scope.
        $this->mark->mark('after the throw');
        $this->mark->leaveHole($uri);

        $this->assertTrue(
            $this->mark->isMarkedForCurrentScope(),
            'a mark right after the refused re-fetch must stay filed under TOP_LEVEL, not get displaced by a scope the throw left stuck on the stack',
        );
    }
}
