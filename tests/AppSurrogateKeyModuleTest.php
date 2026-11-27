<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Entity\Catalog;
use FakeVendor\HelloWorld\Resource\App\UncachedLeaf;
use FakeVendor\HelloWorld\Resource\Page\Html\MutableComment;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function array_count_values;
use function array_diff;
use function array_keys;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_string;

class AppSurrogateKeyModuleTest extends TestCase
{
    public const KEY = 'app-key';

    /** #[Cacheable] (plain, #[DependsOn], over a part over an uncached child), #[CacheableResponse] (hole, material, uncached child) and #[DonutCache] */
    private const PAGES = [
        'page://self/html/like',
        'app://self/rows/ca?channelId=c1',
        'page://self/html/cacheable-over-response-part-over-uncached-leaf',
        'page://self/html/blog-posting',
        'page://self/html/cacheable-response-with-material',
        'page://self/html/response-part-over-uncached-leaf',
        'page://self/html/blog-posting-donut',
    ];
    private const SAVE_EVENTS = ['save_value', 'save_view', 'save_donut', 'save_donut_view'];

    private ResourceInterface $resource;
    private ResourceStorageInterface $storage;
    private QueryRepositoryInterface $repository;
    private SemanticLoggerInterface $logger;
    private FakeCountingPurger $purger;
    private CacheDependencyInterface $cacheDependency;

    /** @return array<string, array{string}> */
    public static function installOrderProvider(): array
    {
        return [
            'installed before QueryRepositoryModule' => ['before'],
            'installed after QueryRepositoryModule' => ['after'],
        ];
    }

    /** First, so a CDN that ignores the keys past a length limit still reads it */
    #[DataProvider('installOrderProvider')]
    public function testEveryResponseCarriesTheKeyFirstAndOnce(string $order): void
    {
        $this->boot($order);
        foreach (self::PAGES as $uri) {
            $this->assertKeyFirstAndOnce($this->header($uri), "{$uri} miss");
            $this->assertKeyFirstAndOnce($this->header($uri), "{$uri} hit");
        }

        // The page view goes with its hole; the donut is composed again
        $this->repository->purge(new Uri('page://self/html/comment'));
        $this->assertKeyFirstAndOnce($this->header('page://self/html/blog-posting'), 'page recomposed around its hole');
    }

    #[DataProvider('installOrderProvider')]
    public function testEveryEntryIsStoredWithTheKeyOnce(string $order): void
    {
        $this->boot($order);
        $saved = $this->requestAll();
        foreach (['page://self/html/like', 'app://self/rows/ca?channelId=c1', 'page://self/html/blog-posting', 'page://self/html/comment', 'page://self/html/cacheable-response-with-material', 'page://self/html/mutable-comment', 'page://self/html/response-part-over-uncached-leaf', 'page://self/html/cacheable-over-response-part-over-uncached-leaf', 'page://self/html/blog-posting-donut'] as $uri) {
            $this->assertArrayHasKey($uri, $saved);
        }

        foreach ($saved as $uri => $tagLists) {
            foreach ($tagLists as $tags) {
                $this->assertSame(1, array_count_values($tags)[self::KEY] ?? 0, $uri);
            }
        }
    }

    #[DataProvider('installOrderProvider')]
    public function testInvalidatingTheKeyDropsEveryEntry(string $order): void
    {
        $this->boot($order);
        $stored = array_keys($this->requestAll());
        foreach ($stored as $uri) {
            $this->assertTrue($this->isStored($uri), "precondition: {$uri} is stored");
        }

        $this->purger->reset();
        $this->storage->invalidateTags([self::KEY]);

        foreach ($stored as $uri) {
            $this->assertFalse($this->isStored($uri), $uri);
        }

        $this->assertSame([self::KEY], $this->purger->tags, 'the CDN is purged by the key');
    }

    /** Both install orders add the key and nothing else; without the module no key is added */
    public function testTheKeyIsAllTheModuleAdds(): void
    {
        $this->boot('none');
        $without = $this->headersAndTags();
        $this->assertNotContains(self::KEY, [...$without['headers'], ...$without['tags']]);

        $this->boot('before');
        $before = $this->headersAndTags();
        $this->boot('after');
        $after = $this->headersAndTags();

        $this->assertSame($before, $after);
        $this->assertSame($without, $this->withoutKey($before));
    }

    public function testDependsLeavesTheKeyToTheWriteThatStoresTheParent(): void
    {
        $this->boot('after');
        $parent = new class extends ResourceObject {
        };
        $parent->uri = new Uri('app://self/parent');
        $parent->headers[Header::SURROGATE_KEY] = 'parent-own';
        $child = new class extends ResourceObject {
        };
        $child->uri = new Uri('app://self/child');
        $child->headers[Header::SURROGATE_KEY] = 'child-own';

        $this->cacheDependency->depends($parent, $child);
        $this->assertSame('parent-own _child_ grp-_child_ child-own', $parent->headers[Header::SURROGATE_KEY], 'not stored');

        $this->repository->put($parent);
        $this->assertNotNull($this->storage->get(new Uri('app://self/parent')));
        $this->assertSame(self::KEY . ' parent-own _child_ grp-_child_ child-own', $parent->headers[Header::SURROGATE_KEY], 'stored');
    }

    public function testInvalidatingTheKeyDropsEntriesSavedDirectly(): void
    {
        $this->boot('after');
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('app://self/direct');
        $ro->body = ['saved' => 'directly'];
        $view = new class extends ResourceObject {
        };
        $view->uri = new Uri('app://self/direct-view');
        $view->view = 'view';
        $this->storage->saveValue($ro, 0);
        $this->storage->saveDonut($ro->uri, new ResourceDonut('template', [], null, false), null, ['direct']);
        $this->storage->saveDonutView($view, null, ['explicit']);
        $this->assertNotNull($this->storage->get($ro->uri));
        $this->assertNotNull($this->storage->getDonut($ro->uri));
        $this->assertNotNull($this->storage->get($view->uri));

        $this->storage->invalidateTags([self::KEY]);

        $this->assertNull($this->storage->get($ro->uri));
        $this->assertNull($this->storage->getDonut($ro->uri));
        $this->assertNull($this->storage->get($view->uri));
    }

    private function boot(string $order): void
    {
        Catalog::reset();
        MutableComment::$comment = 'comment-a';
        UncachedLeaf::$version = 'v1';
        $this->purger = new FakeCountingPurger();
        $base = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $base->override(new TwigModule([__DIR__ . '/Fake/fake-app/var/templates']));
        $base->override(new class ($this->purger) extends AbstractModule {
            public function __construct(private readonly PurgerInterface $purger)
            {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(PurgerInterface::class)->toInstance($this->purger);
            }
        });
        $module = new class ($base, $order) extends AbstractModule {
            public function __construct(private readonly AbstractModule $base, private readonly string $order)
            {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                if ($this->order === 'before') {
                    $this->install(new AppSurrogateKeyModule(AppSurrogateKeyModuleTest::KEY));
                }

                $this->install($this->base);
                if ($this->order === 'after') {
                    $this->install(new AppSurrogateKeyModule(AppSurrogateKeyModuleTest::KEY));
                }
            }
        };

        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->cacheDependency = $injector->getInstance(CacheDependencyInterface::class);
    }

    /**
     * Requests every page, then the page whose hole was purged
     *
     * @return array<string, list<list<string>>> the tags of each entry saved, by URI
     */
    private function requestAll(): array
    {
        foreach (self::PAGES as $uri) {
            $this->resource->get($uri);
        }

        $this->repository->purge(new Uri('page://self/html/comment'));
        $this->resource->get('page://self/html/blog-posting');

        return $this->savedTags();
    }

    /** @return array{headers: array<string, list<string>>, tags: array<string, list<list<string>>>} */
    private function headersAndTags(): array
    {
        $headers = [];
        foreach (self::PAGES as $uri) {
            $headers[$uri] = explode(' ', $this->header($uri) ?? '');
        }

        return ['headers' => $headers, 'tags' => $this->requestAll()];
    }

    /**
     * @param array{headers: array<string, list<string>>, tags: array<string, list<list<string>>>} $result
     *
     * @return array{headers: array<string, list<string>>, tags: array<string, list<list<string>>>}
     */
    private function withoutKey(array $result): array
    {
        $headers = [];
        foreach ($result['headers'] as $uri => $tags) {
            $headers[$uri] = $tags === [self::KEY] ? [''] : self::drop($tags);
        }

        $saved = [];
        foreach ($result['tags'] as $uri => $lists) {
            foreach ($lists as $tags) {
                $saved[$uri][] = self::drop($tags);
            }
        }

        return ['headers' => $headers, 'tags' => $saved];
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private static function drop(array $tags): array
    {
        return array_values(array_diff($tags, [self::KEY]));
    }

    private function header(string $uri): string|null
    {
        return $this->resource->get($uri)->headers[Header::SURROGATE_KEY] ?? null;
    }

    private function assertKeyFirstAndOnce(string|null $header, string $message): void
    {
        $this->assertNotNull($header, $message);
        $keys = explode(' ', $header);
        $this->assertSame(self::KEY, $keys[0], $message);
        $this->assertSame(1, array_count_values($keys)[self::KEY] ?? 0, $message);
    }

    private function isStored(string $uri): bool
    {
        return $this->storage->get(new Uri($uri)) !== null || $this->storage->getDonut(new Uri($uri)) !== null;
    }

    /** @return array<string, list<list<string>>> the tags of each entry saved since the last call, by URI */
    private function savedTags(): array
    {
        $saved = [];
        $this->collectSaved($this->logger->flush()->toArray(), $saved);

        return $saved;
    }

    /**
     * @param array<mixed>                      $node
     * @param array<string, list<list<string>>> $saved
     */
    private function collectSaved(array $node, array &$saved): void
    {
        if (isset($node['type'], $node['context']) && in_array($node['type'], self::SAVE_EVENTS, true) && is_array($node['context'])) {
            $uri = $node['context']['uri'] ?? null;
            /** @var list<string>|null $tags */
            $tags = $node['context']['tags'] ?? null;
            if (is_string($uri) && is_array($tags)) {
                $saved[$uri][] = $tags;
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectSaved($child, $saved);
            }
        }
    }
}
