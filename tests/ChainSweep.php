<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use ArrayAccess;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\HalModule;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Throwable;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function preg_match;
use function range;
use function sort;
use function spl_autoload_register;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function ucfirst;
use function unlink;

/**
 * Every chain of resources up to four levels deep, each read as a miss and then as a hit
 *
 * A chain is a page and one child per level below it. A level below the page is #[Cacheable] or not, lives in page://
 * or app://, and is used by its parent in one of three ways: embedded only, cast to a string in onGet(), or read by
 * array access in onGet(). The leaf shows a version and declares the URI its data comes from. A chain is read with
 * nothing stored, and again with its cacheable levels stored before the page.
 *
 * What is checked for each read:
 *
 * - view:  the hit has the view and the ETag of the miss
 * - sk:    the hit has the Surrogate-Key of the miss
 * - cold:  what was stored beforehand changes neither the view nor the Surrogate-Key
 * - body:  the leaf is readable through the body of the response, miss and hit (#[Cacheable] page)
 * - entry: every level of the stored entry keeps the key its #[Embed] gave it (#[Cacheable] page)
 * - purge: a purge of any cacheable level drops what is stored for the page
 * - fresh: once the leaf's data changes and the URI it declares is purged, the page shows the new data
 *
 * @phpstan-type Chain array{top: string, label: string, uris: list<string>, cacheable: list<int>, warms: list<list<int>>, target: string}
 */
final class ChainSweep
{
    public const TOPS = ['value' => 'Cacheable', 'response' => 'CacheableResponse', 'donut' => 'DonutCache'];
    private const NAMESPACE = 'FakeVendor\Sweep';
    private const USES = ['embed', 'cast', 'read'];
    private const MAX_DEPTH = 4;

    /** The version the generated leaves show */
    public static int $version = 1;

    /** @var list<Chain> */
    private static array $chains = [];
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ArrayAdapter $pool;

    /**
     * @param 'hal'|'json'|'twig'      $renderer
     * @param list<key-of<self::TOPS>> $tops     the kinds of page to read
     * @param string                   $dir      where the generated resources go
     */
    public function __construct(string $renderer, private array $tops, string $dir)
    {
        if (self::$chains === []) {
            self::$chains = self::generate($dir);
        }

        $this->pool = new ArrayAdapter();
        $module = new QueryRepositoryModule(new ResourceModule(self::NAMESPACE));
        if ($renderer === 'hal') {
            $module->override(new HalModule());
        }

        if ($renderer === 'twig') {
            $module->override(new TwigModule([$dir . '/templates']));
        }

        $module->override(new class ($this->pool) extends AbstractModule {
            public function __construct(private ArrayAdapter $pool)
            {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new TagAwareAdapter($this->pool));
            }
        });
        $compiled = "{$dir}/di-{$renderer}";
        self::makeDir($compiled);
        array_map(unlink(...), glob($compiled . '/*.php') ?: []);
        $injector = new Injector($module, $compiled);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
    }

    /** @return list<string> one line per failed check: the check, the chain, and what was stored before the page */
    public function failures(): array
    {
        $failures = [];
        foreach (self::$chains as $chain) {
            if (! in_array($chain['top'], $this->tops, true)) {
                continue;
            }

            $cold = null;
            foreach ($chain['warms'] as $warm) {
                $where = $chain['label'] . ' | stored first: ' . ($warm === [] ? '-' : 'n' . implode(',n', $warm));
                try {
                    foreach ($this->check($chain, $warm, $cold) as $failed) {
                        $failures[] = "{$failed} | {$where}";
                    }
                } catch (Throwable $e) {
                    $failures[] = "error | {$where} | " . $e::class . ': ' . $e->getMessage();
                }
            }
        }

        self::$version = 1;

        return $failures;
    }

    /**
     * @param Chain                      $chain
     * @param list<int>                  $warm  the levels stored before the page
     * @param array{string, string}|null $cold  the view and the Surrogate-Key of the read with nothing stored
     *
     * @return list<string>
     *
     * @param-out array{string, string} $cold
     */
    private function check(array $chain, array $warm, array|null &$cold): array
    {
        $failed = [];
        $top = $chain['uris'][0];
        $depth = count($chain['uris']);
        $leaf = 'n' . ($depth - 1);

        $this->prime($chain, $warm);
        $first = $this->resource->get($top);
        $firstLeaf = self::leafThrough($first->body, $depth);
        $firstView = $first->toString();
        $entry = $this->repository->get(new Uri($top));
        $second = $this->resource->get($top);
        $secondLeaf = self::leafThrough($second->body, $depth);
        if ($firstView !== $second->toString() || ($first->headers['ETag'] ?? null) !== ($second->headers['ETag'] ?? null)) {
            $failed[] = 'view';
        }

        if (self::surrogateKey($first) !== self::surrogateKey($second)) {
            $failed[] = 'sk';
        }

        $cold ??= [$firstView, self::surrogateKey($first)];
        if ($cold !== [$firstView, self::surrogateKey($first)]) {
            $failed[] = 'cold';
        }

        if ($chain['top'] === 'value') {
            if ($firstLeaf !== $leaf || $secondLeaf !== $leaf) {
                $failed[] = 'body';
            }

            if (! self::entryKeepsItsKeys($entry, $depth)) {
                $failed[] = 'entry';
            }
        }

        if ($chain['top'] !== 'donut') {
            foreach ($chain['cacheable'] as $level) {
                $this->prime($chain, $warm);
                $this->resource->get($top);
                $this->repository->purge(new Uri($chain['uris'][$level]));
                if ($this->repository->get(new Uri($top)) !== null) {
                    $failed[] = "purge of n{$level}";
                }
            }
        }

        $this->prime($chain, $warm);
        $this->resource->get($top)->toString();
        self::$version = 2;
        $this->repository->purge(new Uri($chain['target']));
        if (preg_match('/ver\W{1,6}2/', $this->resource->get($top)->toString()) !== 1) {
            $failed[] = 'fresh';
        }

        return $failed;
    }

    /**
     * @param Chain     $chain
     * @param list<int> $warm
     */
    private function prime(array $chain, array $warm): void
    {
        self::$version = 1;
        $this->pool->clear();
        foreach ($warm as $level) {
            $this->resource->get($chain['uris'][$level]);
        }
    }

    private static function surrogateKey(ResourceObject $ro): string
    {
        $keys = array_values(array_filter(explode(' ', $ro->headers['Surrogate-Key'] ?? '')));
        sort($keys);

        return implode(' ', $keys);
    }

    /** The name the leaf gives itself, read down the chain through each 'c' */
    private static function leafThrough(mixed $body, int $depth): string|null
    {
        for ($level = 1; $level < $depth; $level++) {
            if (! is_array($body) && ! $body instanceof ArrayAccess) {
                return null;
            }

            /** @psalm-suppress MixedAssignment */
            $body = $body['c'] ?? null;
        }

        if (! is_array($body) && ! $body instanceof ArrayAccess) {
            return null;
        }

        $name = $body['v'] ?? null;

        return is_string($name) ? $name : null;
    }

    private static function entryKeepsItsKeys(ResourceState|null $entry, int $depth): bool
    {
        $body = $entry?->body;
        for ($level = 1; $level < $depth; $level++) {
            if (! is_array($body) || isset($body['_embedded'])) {
                return false;
            }

            $child = $body['c'] ?? null;
            if (! $child instanceof ResourceObject) {
                return false;
            }

            $body = $child->body;
        }

        return is_array($body) && ! isset($body['_embedded']);
    }

    /** @return list<Chain> */
    private static function generate(string $dir): array
    {
        $chains = [];
        $files = [];
        foreach (self::shapes() as $i => [$top, $cached, $schemes, $uses]) {
            $depth = count($cached) + 1;
            $uris = ["page://self/s{$i}/n0"];
            $label = [];
            foreach (range(1, $depth - 1) as $level) {
                $uris[] = "{$schemes[$level - 1]}://self/s{$i}/n{$level}";
                $label[] = $uses[$level - 1] . '> ' . ($cached[$level - 1] ? 'C' : 'u') . ':' . $schemes[$level - 1];
            }

            $target = "app://self/s{$i}/target";
            foreach (range(0, $depth - 1) as $level) {
                $scheme = ucfirst($level === 0 ? 'page' : $schemes[$level - 1]);
                $attribute = $level === 0 ? self::TOPS[$top] : ($cached[$level - 1] ? 'Cacheable' : null);
                $isLeaf = $level === $depth - 1;
                $statements = "        \$this->body['v'] = 'n{$level}';\n";
                $template = "N{$level}[{{ v }}]";
                if ($isLeaf) {
                    $statements .= "        \$this->body['ver'] = \\" . self::class . "::\$version;\n";
                    $template .= ' ver={{ ver }}';
                }

                if (! $isLeaf) {
                    $template .= '<c>{{ c|raw }}</c>';
                    if ($uses[$level] === 'cast') {
                        $statements .= "        \$this->body['read'] = (string) \$this->body['c'];\n";
                        $template .= '<read>{{ read|raw }}</read>';
                    }

                    if ($uses[$level] === 'read') {
                        $statements .= "        \$this->body['got'] = \$this->body['c']['v'];\n";
                        $template .= '<got>{{ got }}</got>';
                    }
                }

                $files["src/Resource/{$scheme}/S{$i}/N{$level}.php"] = "<?php\n\nnamespace " . self::NAMESPACE . "\\Resource\\{$scheme}\\S{$i};\n\n"
                    . ($attribute === null ? '' : "#[\\BEAR\\RepositoryModule\\Annotation\\{$attribute}]\n")
                    . "class N{$level} extends \\BEAR\\Resource\\ResourceObject\n{\n"
                    . ($isLeaf ? "    #[\\BEAR\\RepositoryModule\\Annotation\\DependsOn(uri: '{$target}')]\n" : "    #[\\BEAR\\Resource\\Annotation\\Embed(rel: 'c', src: '{$uris[$level + 1]}')]\n")
                    . "    public function onGet(): static\n    {\n{$statements}        return \$this;\n    }\n}\n";
                $files["templates/{$scheme}/S{$i}/N{$level}.html.twig"] = $template;
            }

            $cacheable = array_values(array_filter(range(1, $depth - 1), static fn (int $level): bool => $cached[$level - 1]));
            $chains[] = ['top' => $top, 'label' => "{$top} page " . implode(' ', $label), 'uris' => $uris, 'cacheable' => $cacheable, 'warms' => self::warms($cacheable), 'target' => $target];
        }

        foreach ($files as $path => $code) {
            self::makeDir(dirname("{$dir}/{$path}"));
            file_put_contents("{$dir}/{$path}", $code);
        }

        spl_autoload_register(static function (string $class) use ($dir): void {
            if (! str_starts_with($class, self::NAMESPACE . '\\')) {
                return;
            }

            $file = $dir . '/src/' . str_replace('\\', '/', substr($class, strlen(self::NAMESPACE) + 1)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        return $chains;
    }

    /**
     * The page kind, then per level below it: cacheable or not, the scheme, and how its parent uses it
     *
     * @return list<array{key-of<self::TOPS>, list<bool>, list<string>, list<string>}>
     */
    private static function shapes(): array
    {
        $shapes = [];
        foreach (range(2, self::MAX_DEPTH) as $depth) {
            $below = $depth - 1;
            // Four levels would be 5,832 chains per page kind; the schemes and the uses are thinned there
            $full = $depth < self::MAX_DEPTH;
            $schemes = $full ? self::product(array_fill(0, $below, ['page', 'app'])) : [['page', 'page', 'page'], ['app', 'app', 'app'], ['app', 'page', 'app']];
            $uses = self::product(array_fill(0, $below, $full ? self::USES : ['embed', 'cast']));
            foreach (array_keys(self::TOPS) as $top) {
                foreach (self::product(array_fill(0, $below, [true, false])) as $cached) {
                    foreach ($schemes as $scheme) {
                        foreach ($uses as $use) {
                            $shapes[] = [$top, $cached, $scheme, $use];
                        }
                    }
                }
            }
        }

        return $shapes;
    }

    /**
     * What is stored before the page: nothing, every cacheable level from the leaf up, or one cacheable level alone
     *
     * @param list<int> $cacheable
     *
     * @return list<list<int>>
     */
    private static function warms(array $cacheable): array
    {
        if ($cacheable === []) {
            return [[]];
        }

        $warms = [[], array_reverse($cacheable)];
        if (count($cacheable) > 1) {
            foreach ($cacheable as $one) {
                $warms[] = [$one];
            }
        }

        return $warms;
    }

    /**
     * @param list<list<T>> $sets
     *
     * @return list<list<T>>
     *
     * @template T
     */
    private static function product(array $sets): array
    {
        $rows = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($rows as $row) {
                foreach ($set as $value) {
                    $next[] = [...$row, $value];
                }
            }

            $rows = $next;
        }

        return $rows;
    }

    private static function makeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
