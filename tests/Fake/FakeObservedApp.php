<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function array_keys;
use function array_values;
use function assert;
use function dirname;
use function is_array;
use function preg_match;
use function strtr;
use function explode;
use function strtoupper;
use function trim;
use function ksort;

/**
 * The fake app on array pools whose stored entries can be listed by key, with their tags
 *
 * Separate ArrayAdapters hold the tag versions, so the item adapters hold entries only.
 */
final class FakeObservedApp
{
    public readonly InjectorInterface $injector;
    public readonly ResourceInterface $resource;
    private readonly ArrayAdapter $roItems;
    private readonly TagAwareAdapter $roPool;
    /** @var array<string, string> */
    private readonly array $server;

    /**
     * @param array<string, string> $server what ServerContextInterface reads, X_VARY and its X_* values
     * @param bool                  $twig   false renders pages as JSON, for pages that have no template
     */
    public function __construct(array $server = [], bool $twig = true)
    {
        $this->roItems = new ArrayAdapter();
        $this->roPool = new TagAwareAdapter($this->roItems, new ArrayAdapter());
        $this->server = $server;

        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        if ($twig) {
            $module->override(new TwigModule([dirname(__DIR__) . '/Fake/fake-app/var/templates']));
        }

        $module->override(new class ($this->roPool, $server) extends AbstractModule {
            /** @param array<string, string> $server */
            public function __construct(
                private readonly TagAwareAdapter $roPool,
                private readonly array $server,
            ) {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->roPool);
                $this->bind(ServerContextInterface::class)->toInstance(new FakeServerContext($this->server));
            }
        });
        $this->injector = new Injector($module, dirname(__DIR__) . '/tmp');
        $this->resource = $this->injector->getInstance(ResourceInterface::class);
    }

    /**
     * Stored resource-pool entries by key: key => tags
     *
     * @return array<string, list<string>>
     */
    public function roEntries(): array
    {
        $entries = [];
        foreach ($this->hits($this->roItems, $this->roPool) as $key => $item) {
            $entries[$this->readableKey($key, self::tags($item))] = self::tags($item);
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Stored ETag-pool entries by the URI tag each was issued for: URI tag => [opaque-tag, tags]
     *
     * Keyed by URI tag because an opaque-tag hashes the woven class name, which differs between checkouts.
     *
     * @return array<string, array{string, list<string>}>
     */
    public function etagEntries(): array
    {
        $entries = [];
        foreach ($this->hits($this->roItems, $this->roPool) as $key => $item) {
            $state = $item->get();
            if (! $state instanceof ResourceState || ! isset($state->headers[Header::ETAG])) {
                continue;
            }

            $tags = self::tags($item);
            $entries[$tags[0]] = [trim($state->headers[Header::ETAG], '"'), $tags];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The key before hashing, as type, exact tag and vary values
     *
     * A key hashes the URI, and the fake app's URIs hold no "_", so the entry's own tag reads back as one.
     *
     * @param list<string> $tags
     */
    private function readableKey(string $key, array $tags): string
    {
        $factory = new CacheIdentityFactory(new FakeServerContext($this->server));
        preg_match('/\A((?:_[^_=]+)*)_(.*)\z/', $tags[0] ?? '', $tag);
        $pathAndQuery = strtr($tag[1] ?? '', '_', '/') . '?' . strtr($tag[2] ?? '', '_', '&');
        foreach (['ro-', 'donut-'] as $type) {
            foreach (['app', 'page'] as $scheme) {
                if ($factory->storageKey($factory->locator(new Uri($scheme . '://self' . $pathAndQuery)), $type) === $key) {
                    return $type . $tags[0] . $this->vary();
                }
            }
        }

        return $key;
    }

    private function vary(): string
    {
        $vary = '';
        foreach (explode(',', $this->server['X_VARY'] ?? '') as $name) {
            $vary .= $this->server['X_' . strtoupper(trim($name))] ?? '';
        }

        return $vary;
    }

    /** @return array<string, ItemInterface> */
    private function hits(ArrayAdapter $items, TagAwareAdapter $pool): array
    {
        $hits = [];
        // A missed read leaves its key in an ArrayAdapter, so a key alone is not a stored entry
        foreach (array_keys($items->getValues()) as $key) {
            $item = $pool->getItem((string) $key);
            if ($item->isHit()) {
                assert($item instanceof ItemInterface);
                $hits[(string) $key] = $item;
            }
        }

        return $hits;
    }

    /** @return list<string> */
    private static function tags(ItemInterface $item): array
    {
        $tags = $item->getMetadata()[ItemInterface::METADATA_TAGS] ?? [];
        assert(is_array($tags));

        /** @var list<string> */
        return array_values($tags);
    }
}
