<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\AbstractUri;
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
use Ray\Di\InjectorInterface;
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
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->injector = $injector;
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testRefreshResolvesTwoPlaceholdersOnTheSameLine(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>' . ResourceDonut::placeholder('page://self/html/comment') . ResourceDonut::placeholder('page://self/html/like') . '</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->dependencies($ro->uri));

        $this->assertStringContainsString('comment01', (string) $refreshed->view);
        $this->assertStringContainsString('like0', (string) $refreshed->view);
        $this->assertStringNotContainsString('[le2:', (string) $refreshed->view);
    }

    public function testRefreshResolvesPlaceholdersOnSeparateLines(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $donut = new ResourceDonut('<p>' . ResourceDonut::placeholder('page://self/html/comment') . "</p>\n<p>" . ResourceDonut::placeholder('page://self/html/like') . '</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->dependencies($ro->uri));

        $this->assertStringContainsString('comment01', (string) $refreshed->view);
        $this->assertStringContainsString('like0', (string) $refreshed->view);
        $this->assertStringNotContainsString('[le2:', (string) $refreshed->view);
    }

    /**
     * Query values that need percent-encoding: a space, a slash, a non-ASCII character, and the reserved '&' and '='
     *
     * @return array<string, array{0: string}>
     */
    public static function queryValueProvider(): array
    {
        return [
            'encoded space (+)' => ['a b'],
            'encoded slash (%2F)' => ['a/b'],
            'non-ASCII' => ['日本語'],
            'reserved characters (&, =)' => ['a&b=c'],
        ];
    }

    #[DataProvider('queryValueProvider')]
    public function testRefreshRoundTripsThePlaceholdersQueryValue(string $value): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $placeholder = ResourceDonut::placeholder('app://self/query-hole?' . http_build_query(['token' => $value]));
        $donut = new ResourceDonut('<p>' . $placeholder . '</p>', [], null, false);

        $refreshed = $donut->refresh($this->resource, $ro, $this->dependencies($ro->uri));

        $this->assertSame('<p>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</p>', (string) $refreshed->view);
    }

    /**
     * A re-fetch failure is wrapped into CacheStoreException (a RuntimeException subtype),
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
            $donut->refresh($throwingResource, $ro, $this->dependencies($ro->uri));
            $this->fail('expected the re-fetch to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('pool outage', $e->getPrevious()?->getMessage());
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
            ResourceDonut::create($ro, new DonutRenderer(), $this->dependencies($ro->uri), null, false);
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
            ResourceDonut::create($ro, new DonutRenderer(), $this->dependencies($ro->uri), null, false);
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
            ResourceDonut::create($ro, new DonutRenderer(), $this->dependencies($ro->uri), null, false);
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
            ResourceDonut::create($ro, new DonutRenderer(), $this->dependencies($ro->uri), null, false);
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

        $donut = ResourceDonut::create($ro, new DonutRenderer(), $this->dependencies($ro->uri), null, false);
        $rendered = $donut->render($ro, new DonutRenderer());

        $this->assertStringContainsString('plain-donut-hole:plain', (string) $rendered->view);
    }

    /**
     * refresh()'s per-hole `$ro->toString()` (and the `$resource->get($uri)` re-fetch that
     * runs the hole's onGet()) does not swallow a Throwable either - it propagates directly,
     * the same non-swallowing render create() itself uses. Unlike create(), which lets it
     * travel unwrapped, this is a plain try/catch converting it to CacheStoreException: a
     * HIT's re-fetch degrades through the interceptor's read path instead, falling back to
     * a full fresh render.
     */
    public function testRefreshRefusesToServeWhenAHoleThrows(): void
    {
        $ro = $this->resource->newInstance('page://self/html/blog-posting-donut');
        $uri = 'app://self/throwing-hole';
        $donut = new ResourceDonut('<p>' . ResourceDonut::placeholder($uri) . '</p>', [], null, false);

        try {
            $donut->refresh($this->resource, $ro, $this->dependencies($ro->uri));
            $this->fail('expected the hole throw to be refused as a CacheStoreException');
        } catch (CacheStoreException $e) {
            $this->assertStringContainsString('the hole blew up mid-render', (string) $e->getPrevious()?->getMessage());
        }
    }

    private function dependencies(AbstractUri $uri): Dependencies
    {
        return $this->injector->getInstance(CacheDependencyCollector::class)->begin(new TagSet([(new UriTag())($uri)]));
    }
}
