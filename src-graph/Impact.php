<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_shift;
use function str_starts_with;

/**
 * What a write or an invalidation reaches, transitively, through the declared graph
 *
 * A line per reached URI, in breadth-first order, each reached once by its shortest path.
 * The shape is `docs/schemas/graph/impact.json`.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type ImpactLine = array{uri: string, via: string, by: string|null, depth: int, cached: bool, source: string|null}
 */
final class Impact
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, list<string>> child URI => URIs of the resources embedding it */
    private array $embeddedBy = [];

    /** @param list<Node> $nodes */
    public function __construct(array $nodes)
    {
        foreach ($nodes as $node) {
            $this->nodes[$node['uri']] = $node;
            foreach ($node['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $this->embeddedBy[$embed['uri']][] = $node['uri'];
                }
            }
        }
    }

    /**
     * @param string      $uri    an instance (`app://self/user?id=1`) or a node URI
     * @param string|null $method a write method of `$uri`: start from what it refreshes and purges
     *
     * @return list<ImpactLine>
     */
    public function __invoke(string $uri, string|null $method = null): array
    {
        $uri = (new UriConverter())->resolve($uri, $uri)['uri'];
        /** @var list<array{uri: string, via: string, by: string|null, depth: int}> $queue */
        $queue = [];
        if ($method === null) {
            $queue[] = ['uri' => $uri, 'via' => 'target', 'by' => null, 'depth' => 0];
        } else {
            $declared = $this->nodes[$uri]['methods'][$method] ?? [];
            foreach ($declared['refresh'] ?? [] as $target) {
                $queue[] = ['uri' => $target['uri'], 'via' => 'refresh', 'by' => $uri, 'depth' => 1];
            }

            foreach ($declared['purge'] ?? [] as $target) {
                $queue[] = ['uri' => $target['uri'], 'via' => 'purge', 'by' => $uri, 'depth' => 1];
            }
        }

        $lines = [];
        $seen = [];
        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($seen[$next['uri']])) {
                continue;
            }

            $seen[$next['uri']] = true;
            $node = $this->nodes[$next['uri']] ?? null;
            $lines[] = $next + ['cached' => $node !== null && $this->isCached($node), 'source' => $node['source'] ?? null];
            foreach ($this->embeddedBy[$next['uri']] ?? [] as $parent) {
                $queue[] = ['uri' => $parent, 'via' => 'embed', 'by' => $next['uri'], 'depth' => $next['depth'] + 1];
            }
        }

        return $lines;
    }

    /** @param Node $node */
    private function isCached(array $node): bool
    {
        if (isset($node['cacheable']) || isset($node['donutCache']) || isset($node['cacheableResponse'])) {
            return true;
        }

        foreach ($node['methods'] ?? [] as $name => $method) {
            if (str_starts_with($name, 'onGet') && isset($method['cacheableResponse'])) {
                return true;
            }
        }

        return false;
    }
}
