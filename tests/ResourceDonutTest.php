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
use RuntimeException;
use Twig\Error\Error as TwigError;

use function assert;
use function dirname;
use function htmlspecialchars;
use function http_build_query;

use const ENT_QUOTES;

class ResourceDonutTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testRefreshResolvesTwoPlaceholdersOnTheSameLine(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>[le:page://self/html/comment][le:page://self/html/like]</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro);

        $this->assertStringContainsString('comment01', (string) $refreshed->view);
        $this->assertStringContainsString('like0', (string) $refreshed->view);
        $this->assertStringNotContainsString('[le:', (string) $refreshed->view);
    }

    public function testRefreshResolvesPlaceholdersOnSeparateLines(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut("<p>[le:page://self/html/comment]</p>\n<p>[le:page://self/html/like]</p>", [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro);

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

        $refreshed = $donut->refresh($this->resource, $ro);

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

        $refreshed = $donut->refresh($this->resource, $ro);

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

        $refreshed = $donut->refresh($this->resource, $ro);

        $this->assertSame('<p>a</p>', (string) $refreshed->view, 'the first token "wins" exactly as it already did - not "b", and not an exception');
    }

    /**
     * A legacy `[le:URI]` placeholder for a hole with two REQUIRED query params, corrupted
     * the same way, fails the re-fetch with the same underlying cause as a pre-#232-fix
     * refresh() threw - still a ParameterException, not this fix's rawurldecode() turning it
     * into a different, new failure. That cause arrives wrapped in a CacheStoreFailure, the
     * same conversion any re-fetch failure gets, so `AbstractDonutCacheInterceptor`'s read
     * path degrades to a fresh render instead of a 500.
     */
    public function testRefreshOfALegacyMultiParamPlaceholderFailsTheSameWayAsBeforeThisFix(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>[le:app://self/multi-param-query-hole?id=1&amp;tag=2]</p>', [], null, false);

        try {
            $donut->refresh($this->resource, $ro);
            $this->fail('expected the malformed legacy placeholder to fail the re-fetch');
        } catch (CacheStoreFailure $e) {
            $this->assertInstanceOf(ParameterException::class, $e->getPrevious());
        }
    }

    /**
     * A re-fetch failure is wrapped into CacheStoreFailure (a RuntimeException subtype),
     * so a plain pool outage or a broken onGet() degrades the refresh instead of leaving a
     * raw, uncategorized exception for the interceptor's read path to handle.
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
            $donut->refresh($throwingResource, $ro);
            $this->fail('expected the re-fetch to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('pool outage', $e->getMessage());
        }
    }

    /**
     * A hole's own onGet() propagates uncaught through `DonutRequest::__toString()`
     * (issue: storing an empty donut template whose hole threw, since 1.17.0). `create()`
     * renders with `$ro->toString()`, which does not swallow, so the ORIGINAL Throwable -
     * here wrapped once by Twig as it bubbles through template evaluation - reaches the
     * caller unmodified. Nothing past the render call ever runs, so nothing is stored.
     */
    public function testCreateLetsAHoleThrowTravelAndStoresNothing(): void
    {
        $ro = $this->resource->newInstance('page://self/html/donut-cache-with-throwing-hole');
        $resource = $this->resource;
        assert($resource instanceof Resource);
        $throwingRequest = $resource->get->uri('app://self/throwing-hole');
        assert($throwingRequest instanceof AbstractRequest);
        $ro->body = ['secret' => $throwingRequest];

        try {
            ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false);
            $this->fail('expected the hole throw to travel');
        } catch (TwigError $e) {
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
            $this->assertSame('the hole blew up mid-render', $e->getPrevious()->getMessage());
        }
    }

    /**
     * Unlike `app://self/throwing-hole` (fails inside `($this->request)()`, before
     * `DonutRequest::__toString()` ever reaches its own render step), this hole's `onGet()`
     * succeeds and its OWN template is what fails - specifically exercising the
     * `$executed->toString()` call (not `(string) $executed`, which would swallow this into
     * an E_USER_WARNING and an empty placeholder view instead of letting it travel).
     */
    public function testCreateLetsAHolesOwnRenderFailureTravelAndStoresNothing(): void
    {
        $ro = $this->resource->newInstance('page://self/html/donut-cache-with-throwing-hole');
        $resource = $this->resource;
        assert($resource instanceof Resource);
        $holeRequest = $resource->get->uri('app://self/hole-with-no-template');
        assert($holeRequest instanceof AbstractRequest);
        $ro->body = ['secret' => $holeRequest];

        try {
            ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false);
            $this->fail("expected the hole's own render failure to travel");
        } catch (TwigError $e) {
            $this->assertStringContainsString('HoleWithNoTemplate', $e->getMessage());
        }
    }

    /**
     * An anonymous exception class (`new class extends RuntimeException {}`) must travel
     * exactly like a named one - Kimi's MEDIUM: a class-name-matching guard would silently
     * miss this and still store the empty template. This design never inspects the class at
     * all, so there is nothing for an anonymous class to evade.
     */
    public function testCreateLetsAnAnonymousExceptionHoleThrowTravelAndStoresNothing(): void
    {
        $ro = $this->resource->newInstance('page://self/html/donut-cache-with-throwing-hole');
        $resource = $this->resource;
        assert($resource instanceof Resource);
        $throwingRequest = $resource->get->uri('app://self/anonymous-throwing-hole');
        assert($throwingRequest instanceof AbstractRequest);
        $ro->body = ['secret' => $throwingRequest];

        try {
            ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false);
            $this->fail('expected the anonymous-class hole throw to travel');
        } catch (TwigError $e) {
            $this->assertStringContainsString('anonymous hole blew up', $e->getPrevious()?->getMessage() ?? '');
        }
    }

    /**
     * The same holds when the composing page's OWN template fails, outside any hole -
     * `$ro->toString()` in create() renders the page itself, not only its holes.
     */
    public function testCreateLetsAPageTemplateThrowTravelAndStoresNothing(): void
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
        $ro = $resource->newInstance('page://self/html/plain-donut-hole');
        $ro->body = ['value' => 'plain'];

        try {
            ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false);
            $this->fail('expected the page template throw to travel');
        } catch (FakeTemplateNotFound $e) {
            $this->assertSame('template not found: user.html.twig', $e->getMessage());
        }
    }

    /**
     * The render must not be disturbed when nothing throws - `$ro->toString()` behaves
     * exactly like the magic cast it replaced for the success path, only diverging on a
     * Throwable.
     */
    public function testCreateStillStoresTheTemplateWhenNothingThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/plain-donut-hole');
        $ro->body = ['value' => 'plain'];

        $donut = ResourceDonut::create($ro, new DonutRenderer(), new SurrogateKeys($ro->uri), null, false);
        $rendered = $donut->render($ro, new DonutRenderer());

        $this->assertStringContainsString('plain-donut-hole:plain', (string) $rendered->view);
    }

    /**
     * refresh()'s per-hole `$ro->toString()` (and the `$resource->get($uri)` re-fetch that
     * runs the hole's onGet()) does not swallow a Throwable either - it propagates directly,
     * the same non-swallowing render create() itself uses. Unlike create(), which lets it
     * travel unwrapped, this is a plain try/catch converting it to CacheStoreFailure: a
     * HIT's re-fetch degrades through the interceptor's read path instead, falling back to
     * a full fresh render.
     */
    public function testRefreshRefusesToServeWhenAHoleThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $uri = 'app://self/throwing-hole';
        $donut = new ResourceDonut('<p>' . ResourceDonut::placeholder($uri) . '</p>', [], null, false);

        try {
            $donut->refresh($this->resource, $ro);
            $this->fail('expected the hole throw to be refused as a CacheStoreFailure');
        } catch (CacheStoreFailure $e) {
            $this->assertStringContainsString('the hole blew up mid-render', $e->getMessage());
        }
    }
}
