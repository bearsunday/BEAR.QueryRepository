<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
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
use function is_string;
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
    private readonly ArrayAdapter $etagItems;
    private readonly TagAwareAdapter $etagPool;

    /**
     * @param array<string, string> $server what ServerContextInterface reads, X_VARY and its X_* values
     * @param bool                  $twig   false renders pages as JSON, for pages that have no template
     */
    public function __construct(array $server = [], bool $twig = true)
    {
        $this->roItems = new ArrayAdapter();
        $this->roPool = new TagAwareAdapter($this->roItems, new ArrayAdapter());
        $this->etagItems = new ArrayAdapter();
        $this->etagPool = new TagAwareAdapter($this->etagItems, new ArrayAdapter());

        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        if ($twig) {
            $module->override(new TwigModule([dirname(__DIR__) . '/Fake/fake-app/var/templates']));
        }

        $module->override(new class ($this->roPool, $this->etagPool, $server) extends AbstractModule {
            /** @param array<string, string> $server */
            public function __construct(
                private readonly TagAwareAdapter $roPool,
                private readonly TagAwareAdapter $etagPool,
                private readonly array $server,
            ) {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->roPool);
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance($this->etagPool);
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
            $entries[$key] = self::tags($item);
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
        foreach ($this->hits($this->etagItems, $this->etagPool) as $key => $item) {
            $issuedFor = $item->get();
            assert(is_string($issuedFor) && ! isset($entries[$issuedFor]));
            $entries[$issuedFor] = [(string) $key, self::tags($item)];
        }

        ksort($entries);

        return $entries;
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
