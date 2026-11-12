<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Entity\ArrayUlid;
use FakeVendor\HelloWorld\Entity\Catalog;
use FakeVendor\HelloWorld\Entity\PrivateUlid;
use FakeVendor\HelloWorld\Entity\Ulid;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function array_count_values;
use function array_filter;
use function array_values;
use function dirname;
use function explode;
use function str_starts_with;

/** A write to a URI that #[DependsOn] names invalidates the stored resource and every page that embeds it */
class DependsOnTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        Catalog::reset();

        parent::setUp();
    }

    /** @return array<string, array{0: string, 1: bool}> uri, whether the entry is donut-stored */
    public static function shapeProvider(): array
    {
        return [
            'class #[Cacheable]' => ['app://self/rows/ca?channelId=c1', false],
            'class #[CacheableResponse], snake_case query' => ['app://self/rows/cr?channel_id=c1', true],
            'method #[CacheableResponse]' => ['app://self/rows/mr?channelId=c1', true],
            'putStatic() in onGet()' => ['app://self/rows/ps?channelId=c1', true],
        ];
    }

    /** @return array<string, array{0: string, 1: bool, 2: string}> */
    public static function shapeAndTargetProvider(): array
    {
        $cases = [];
        foreach (self::shapeProvider() as $shape => [$uri, $isDonut]) {
            $cases["{$shape}, onGet() argument"] = [$uri, $isDonut, 'app://self/rows/taxonomy?ulid=c1'];
            $cases["{$shape}, row property"] = [$uri, $isDonut, 'app://self/rows/article?ulid=a3'];
            $cases["{$shape}, row parent class"] = [$uri, $isDonut, 'app://self/rows/taxonomy?ulid=s1'];
        }

        return $cases;
    }

    #[DataProvider('shapeAndTargetProvider')]
    public function testAWriteToADeclaredUriInvalidatesTheList(string $uri, bool $isDonut, string $target): void
    {
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, $isDonut));

        $this->resource->put('app://self/rows/article?ulid=a7');
        $this->resource->put('app://self/rows/taxonomy?ulid=c2');
        $this->assertTrue($this->isStored($uri, $isDonut), 'a write to a URI of another channel must not invalidate the list');

        $this->resource->put($target);
        $this->assertFalse($this->isStored($uri, $isDonut));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function pageProvider(): array
    {
        return [
            '#[Cacheable] page' => ['app://self/rows/page-value', false],
            'class #[CacheableResponse] page reading the list' => ['app://self/rows/page-view', true],
            'method #[CacheableResponse] page reading the list' => ['app://self/rows/page-donut', true],
            'page <- mid <- list' => ['app://self/rows/page-chain', false],
            'donut page <- mid <- list' => ['app://self/rows/page-chain-donut', true],
            'page <- outer <- mid <- list' => ['app://self/rows/page-deep-chain', false],
        ];
    }

    /** The declarations of an uncached list reach the page that stores it, through uncached resources in between */
    #[DataProvider('pageProvider')]
    public function testTheDeclarationsOfAnEmbeddedListReachThePage(string $uri, bool $isDonut): void
    {
        foreach (['app://self/rows/taxonomy?ulid=c1', 'app://self/rows/article?ulid=a3'] as $target) {
            $this->resource->get($uri);
            $this->assertTrue($this->isStored($uri, $isDonut));

            $this->resource->put('app://self/rows/article?ulid=a7');
            $this->assertTrue($this->isStored($uri, $isDonut));

            $this->resource->put($target);
            $this->assertFalse($this->isStored($uri, $isDonut), "a write to {$target} must invalidate {$uri}");
        }
    }

    /** A list that runs only as its mid renders is read with that render, so the page's view depends on it */
    public function testAListThatRunsOnlyAsItsMidRendersIsADependencyOfThePageView(): void
    {
        $uri = new Uri('app://self/rows/page-touch-lazy-mid');
        foreach (['app://self/rows/taxonomy?ulid=c1', 'app://self/rows/article?ulid=a3'] as $target) {
            $this->resource->get((string) $uri);
            $this->assertNotNull($this->storage->get($uri));

            $this->resource->put('app://self/rows/article?ulid=a7');
            $this->assertNotNull($this->storage->get($uri));

            $this->resource->put($target);
            $this->assertNull($this->storage->get($uri), "a write to {$target} must invalidate the view");
        }
    }

    public function testAListThatAnswers404IsStillADependencyOfItsPage(): void
    {
        $uri = 'app://self/rows/page404';
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=c1');
        $this->assertFalse($this->isStored($uri, false));
    }

    public function testAnEmptyListIsStillADependencyOfItsPage(): void
    {
        $uri = 'app://self/rows/page-empty';
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=empty');
        $this->assertFalse($this->isStored($uri, false));
    }

    public function testTheDeclarationsOfAListRenderedAsAHoleReachThePageHeader(): void
    {
        $page = $this->resource->get('app://self/rows/page-hole');

        $keys = $this->keys($page);
        $this->assertContains($this->tag('app://self/rows/taxonomy?ulid=c1'), $keys);
        $this->assertContains($this->tag('app://self/rows/article?ulid=a3'), $keys);
    }

    public function testAnArgumentOmittedFromTheQueryExpandsFromItsDefault(): void
    {
        $uri = 'app://self/rows/defaulted';
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=c9');
        $this->assertFalse($this->isStored($uri, false));
    }

    public function testANullValueNamesNoUri(): void
    {
        $keys = $this->keys($this->resource->get('app://self/rows/null-values'));

        $this->assertContains($this->tag('app://self/rows/article?ulid=a1'), $keys);
        $this->assertContains($this->tag('app://self/rows/taxonomy?ulid=s1'), $keys);
        $this->assertNotContains($this->tag('app://self/rows/taxonomy'), $keys);
        $this->assertNotContains($this->tag('app://self/rows/taxonomy?ulid='), $keys);
    }

    public function testAStringableValueExpandsAsAString(): void
    {
        $uri = 'app://self/rows/stringable-ulid';
        $this->resource->get($uri);

        $this->resource->put('app://self/rows/article?ulid=a1');
        $this->assertFalse($this->isStored($uri, false));
    }

    public function testRowsThatReferToEachOtherAreEachReadOnce(): void
    {
        $keys = $this->keys($this->resource->get('app://self/rows/cyclic'));

        $this->assertSame(1, array_count_values($keys)[$this->tag('app://self/rows/article?ulid=a1')] ?? 0);
        $this->assertSame(1, array_count_values($keys)[$this->tag('app://self/rows/article?ulid=a3')] ?? 0);
    }

    public function testTheDeclarationOnAnOverriddenOnGetIsRead(): void
    {
        $uri = 'app://self/rows/overriding?channelId=c1';
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=c1');
        $this->assertFalse($this->isStored($uri, false));
    }

    /** With a UriTagInterface of the application's own, every tag of a donut page takes its format, when the page is stored and when it is refreshed */
    public function testEveryTagOfADonutPageTakesTheBoundUriTagFormatWhenStoredAndRefreshed(): void
    {
        $uriTag = new class implements UriTagInterface {
            public function __invoke(AbstractUri $uri): string
            {
                return 'x-' . (new UriTag())($uri);
            }

            /** @param list<array<string, mixed>> $assoc */
            public function fromAssoc(string $uriTemplate, array $assoc): string
            {
                return 'x-' . (new UriTag())->fromAssoc($uriTemplate, $assoc);
            }
        };
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new class ($uriTag) extends AbstractModule {
            public function __construct(private readonly UriTagInterface $uriTag)
            {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(UriTagInterface::class)->toInstance($this->uriTag);
            }
        });
        $resource = (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
        foreach (['stored', 'refreshed'] as $round) {
            $keys = $this->keys($resource->get('app://self/rows/page-hole'));
            $this->assertContains('x-_rows_taxonomy_ulid=c1', $keys, "the {$round} page");
            $this->assertContains('x-_rows_page-hole_', $keys, "the {$round} page");
            $this->assertContains('x-_rows_plain_channelId=c1', $keys, "the {$round} page");
            $this->assertSame([], array_values(array_filter($keys, static fn (string $key): bool => ! str_starts_with($key, 'x-') && ! str_starts_with($key, 'grp-x-'))), "the {$round} page");
        }
    }

    /** A URI named by two declarations and fromAssoc() is tagged once, and both it and a hand-written key still invalidate */
    public function testAUriDeclaredTwiceIsTaggedOnce(): void
    {
        $uri = 'app://self/rows/doubled?channelId=c1';
        $keys = $this->keys($this->resource->get($uri));
        $this->assertSame(1, array_count_values($keys)[$this->tag('app://self/rows/taxonomy?ulid=c1')] ?? 0);
        $this->assertContains('hand-key', $keys);

        $this->resource->put('app://self/rows/taxonomy?ulid=c1');
        $this->assertFalse($this->isStored($uri, false));

        $this->resource->get($uri);
        $this->storage->invalidateTags(['hand-key']);
        $this->assertFalse($this->isStored($uri, false));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function notExpandableProvider(): array
    {
        return [
            'private property' => ['app://self/rows/private-row', 'app://self/rows/private-row ' . PrivateUlid::class, "no 'ulid'"],
            'array property' => ['app://self/rows/array-row', 'app://self/rows/array-row ' . ArrayUlid::class, "'ulid' is array"],
            'variable not an onGet() parameter' => ['app://self/rows/not-parameter?channelId=c1', 'app://self/rows/not-parameter?channelId=c1 onGet()', "'ulid' is not an onGet() parameter"],
            'declared on a resource class' => ['app://self/rows/class-declared?channelId=c1', 'app://self/rows/class-declared?channelId=c1', 'declared on a resource class'],
            'variable bound by #[ResourceParam]' => ['app://self/rows/resource-param-bound', 'app://self/rows/resource-param-bound onGet()', "'channelId' is bound by #[ResourceParam]"],
            'variable bound by #[ResourceParam] and also in the query' => ['app://self/rows/resource-param-bound?channelId=c1', 'app://self/rows/resource-param-bound?channelId=c1 onGet()', "'channelId' is bound by #[ResourceParam]"],
            'variable bound by a method-level #[ResourceParam]' => ['app://self/rows/method-resource-param-bound', 'app://self/rows/method-resource-param-bound onGet()', "'channelId' is bound by #[ResourceParam]"],
        ];
    }

    #[DataProvider('notExpandableProvider')]
    public function testADeclarationThatCannotExpandThrowsAndStoresNothing(string $uri, string $source, string $reason): void
    {
        try {
            $this->resource->get($uri);
            $this->fail('DependsOnNotExpandable must be thrown');
        } catch (DependsOnNotExpandable $e) {
            $this->assertSame($source, $e->source);
            $this->assertSame($reason, $e->reason);
        }

        $this->assertFalse($this->isStored($uri, false));
    }

    /** As BEAR.Resource binds, only the onGet() that runs counts: a #[ResourceParam] on the one it overrides binds nothing */
    public function testAParameterOfAnOverridingOnGetExpandsFromTheQuery(): void
    {
        $uri = 'app://self/rows/resource-param-override?channelId=c1';
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=c1');
        $this->assertFalse($this->isStored($uri, false));
    }

    /** @return array<string, array{0: string}> */
    public static function storeProvider(): array
    {
        return [
            'class #[Cacheable]' => ['app://self/rows/ca?channelId=c1'],
            'class #[CacheableResponse]' => ['app://self/rows/cr?channelId=c1'],
            'method #[CacheableResponse]' => ['app://self/rows/mr?channelId=c1'],
            'putStatic() in onGet()' => ['app://self/rows/ps?channelId=c1'],
            '#[Cacheable] page' => ['app://self/rows/page-value'],
            'class #[CacheableResponse] page reading the list' => ['app://self/rows/page-view'],
            'method #[CacheableResponse] page reading the list' => ['app://self/rows/page-donut'],
            'page with the list as a hole' => ['app://self/rows/page-hole'],
            'donut page <- mid <- list' => ['app://self/rows/page-chain-donut'],
        ];
    }

    /** A material is classified before its page renders and rendered again as a request of its own */
    #[DataProvider('storeProvider')]
    public function testEachRowIsExpandedOncePerStore(string $uri): void
    {
        Ulid::$casts = 0;
        $this->resource->get($uri);

        $this->assertSame(2, Ulid::$casts, 'each of the two rows expands its ULID once');
    }

    private function isStored(string $uri, bool $isDonut): bool
    {
        $abstractUri = new Uri($uri);

        return $isDonut ? $this->storage->getDonut($abstractUri) !== null : $this->repository->get($abstractUri) !== null;
    }

    /** @return list<string> */
    private function keys(ResourceObject $ro): array
    {
        return explode(' ', $ro->headers[Header::SURROGATE_KEY] ?? '');
    }

    private function tag(string $uri): string
    {
        return (new UriTag())(new Uri($uri));
    }
}
