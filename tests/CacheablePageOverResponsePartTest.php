<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\UncachedLeaf;
use FakeVendor\HelloWorld\Resource\App\VersionedLeaf;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_diff;
use function dirname;
use function explode;

/**
 * A #[Cacheable] page shows a #[CacheableResponse] part through #[Embed], and the part embeds a resource of its own
 *
 * Storing the part renders it as a donut, which must not leave the page anything it cannot store.
 */
class CacheablePageOverResponsePartTest extends TestCase
{
    private const PAGE = 'page://self/html/cacheable-over-response-part';
    private const PART = 'page://self/html/response-part';
    private const CHILD = 'app://self/versioned-leaf';
    private const PAGE_OVER_UNCACHED = 'page://self/html/cacheable-over-response-part-over-uncached-leaf';
    private const PART_OVER_UNCACHED = 'page://self/html/response-part-over-uncached-leaf';
    private const PAGE_OVER_DONUT = 'page://self/html/cacheable-over-donut-part';
    private const DONUT_PART = 'page://self/html/donut-part';

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        // The pools outlive one test; start with nothing stored
        foreach ([self::PAGE, self::PART, self::CHILD, self::PAGE_OVER_UNCACHED, self::PART_OVER_UNCACHED, self::PAGE_OVER_DONUT, self::DONUT_PART] as $uri) {
            $this->repository->purge(new Uri($uri));
        }

        VersionedLeaf::$version = 'v1';
        UncachedLeaf::$version = 'v1';
    }

    /** @return iterable<string, array{string, string}> */
    public static function pages(): iterable
    {
        yield 'cached child' => [self::PAGE, "<page><part><leaf>v1</leaf>\n</part>\n</page>\n"];
        yield 'uncached child' => [self::PAGE_OVER_UNCACHED, "<page><part><uncached-leaf>v1</uncached-leaf>\n</part>\n</page>\n"];
        yield '#[DonutCache] part' => [self::PAGE_OVER_DONUT, "<page><part><leaf>v1</leaf>\n</part>\n</page>\n"];
    }

    #[DataProvider('pages')]
    public function testThePageIsStoredAndRendersTheSameOnMissAndHit(string $uri, string $html): void
    {
        $this->assertSame($html, $this->resource->get($uri)->toString());
        $this->assertInstanceOf(ResourceState::class, $this->repository->get(new Uri($uri)));
        $this->assertSame($html, $this->resource->get($uri)->toString());
    }

    /** The page stores the part with the resource it embeds materialized, not the wrapper the part's donut render left */
    public function testThePageStoresThePartWithItsChildMaterialized(): void
    {
        $this->resource->get(self::PAGE);

        $state = $this->repository->get(new Uri(self::PAGE));
        $this->assertInstanceOf(ResourceState::class, $state);
        $this->assertIsArray($state->body);
        $part = $state->body['part'];
        $this->assertInstanceOf(ResourceObject::class, $part);
        $this->assertIsArray($part->body);
        $child = $part->body['child'];
        $this->assertInstanceOf(ResourceObject::class, $child);
        $this->assertSame(['version' => 'v1'], $child->body);
    }

    /** @return iterable<string, array{string, string}> */
    public static function partsOverCachedChild(): iterable
    {
        yield '#[CacheableResponse] part' => [self::PAGE, self::PART];
        yield '#[DonutCache] part' => [self::PAGE_OVER_DONUT, self::DONUT_PART];
    }

    #[DataProvider('partsOverCachedChild')]
    public function testThePageCarriesThePartAndTheChildTagsOnMissAndHit(string $page, string $part): void
    {
        $expected = [(new UriTag())(new Uri($part)), (new UriTag())(new Uri(self::CHILD))];

        $miss = $this->resource->get($page);
        $this->assertEmpty(array_diff($expected, explode(' ', $miss->headers[Header::SURROGATE_KEY])));
        $hit = $this->resource->get($page);
        $this->assertEmpty(array_diff($expected, explode(' ', $hit->headers[Header::SURROGATE_KEY])));
    }

    #[DataProvider('partsOverCachedChild')]
    public function testAPurgeOfTheChildRefreshesThePage(string $page): void
    {
        $this->resource->get($page);

        VersionedLeaf::$version = 'v2';
        $this->repository->purge(new Uri(self::CHILD));

        $this->assertNull($this->repository->get(new Uri($page)));
        $this->assertSame("<page><part><leaf>v2</leaf>\n</part>\n</page>\n", $this->resource->get($page)->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function parts(): iterable
    {
        yield 'cached child' => [self::PAGE, self::PART];
        yield 'uncached child' => [self::PAGE_OVER_UNCACHED, self::PART_OVER_UNCACHED];
        yield '#[DonutCache] part' => [self::PAGE_OVER_DONUT, self::DONUT_PART];
    }

    #[DataProvider('parts')]
    public function testAPurgeOfThePartDropsThePage(string $page, string $part): void
    {
        $this->resource->get($page);

        $this->repository->purge(new Uri($part));

        $this->assertNull($this->repository->get(new Uri($page)));
    }

    public function testAPurgeOfThePartRefreshesThePageWithItsUncachedChild(): void
    {
        $this->resource->get(self::PAGE_OVER_UNCACHED);

        UncachedLeaf::$version = 'v2';
        $this->repository->purge(new Uri(self::PART_OVER_UNCACHED));

        $this->assertSame("<page><part><uncached-leaf>v2</uncached-leaf>\n</part>\n</page>\n", $this->resource->get(self::PAGE_OVER_UNCACHED)->toString());
    }
}
