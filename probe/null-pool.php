<?php

declare(strict_types=1);

// pool を NullAdapter に束縛すると（storage の module を入れない既定と同じ）、#[CacheableResponse] の page は
// origin に保存されないまま、Surrogate-Key と CDN の寿命のヘッダーが付き、書き込みで CDN の purge が走るかを確かめる。
use BEAR\QueryRepository\Cdn\FastlyCacheControlHeaderSetter;
use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\PurgerInterface;
use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';

final class RecordingPurger implements PurgerInterface
{
    /** @var list<string> */
    public static array $purged = [];

    public function __invoke(string $tag): void
    {
        self::$purged[] = $tag;
    }
}

/** @return iterable<array<string, mixed>> */
function events(array $node, array $types): iterable
{
    foreach ($node['log'] ?? [] as $entry) {
        if (isset($entry['type']) && in_array($entry['type'], $types, true)) {
            yield $entry;
        }
        yield from events($entry, $types);
    }
}

foreach (['既定の CDN setter' => null, 'Fastly の setter' => FastlyCacheControlHeaderSetter::class] as $label => $setter) {
    $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
    $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
    $module->override(new class ($setter) extends AbstractModule {
        public function __construct(private string|null $setter)
        {
            parent::__construct();
        }

        protected function configure(): void
        {
            $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(NullAdapter::class);
            $this->bind(PurgerInterface::class)->to(RecordingPurger::class);
            if ($this->setter !== null) {
                $this->bind(CdnCacheControlHeaderSetterInterface::class)->to($this->setter);
            }
        }
    });
    $injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
    $resource = $injector->getInstance(ResourceInterface::class);
    $repository = $injector->getInstance(QueryRepositoryInterface::class);
    $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
    $logger->flush();

    echo "== $label\n";
    foreach ([1, 2] as $round) {
        $ro = $resource->get('page://self/html/blog-posting'); // #[CacheableResponse]、穴は #[Cacheable] の comment
        (string) $ro;
        $hitMiss = array_map(static fn (array $e): string => $e['type'] . ':' . ($e['context']['kind'] ?? $e['context']['type'] ?? ''), iterator_to_array(events($logger->flush()->toArray(), ['cache_hit', 'cache_miss']), false));
        printf("  GET %d 回目: Age=%s  %s\n", $round, $ro->headers['Age'] ?? '-', implode(' ', $hitMiss));
    }
    foreach ($ro->headers as $name => $value) {
        if (in_array($name, ['Surrogate-Key', 'CDN-Cache-Control', 'Surrogate-Control', 'Cache-Control'], true)) {
            echo "  $name: $value\n";
        }
    }
    $pageKeys = explode(' ', $ro->headers['Surrogate-Key'] ?? '');
    foreach (['page://self/html/comment', 'page://self/html/blog-posting'] as $uri) {
        RecordingPurger::$purged = [];
        $repository->purge(new Uri($uri));
        $sent = explode(' ', implode(' ', RecordingPurger::$purged));
        $hit = array_values(array_intersect($sent, $pageKeys));
        printf("  purge %-30s cdn<- %-45s page に当たる: %s\n", $uri, implode(' ', $sent), $hit ? 'yes' : 'NO');
    }
}
