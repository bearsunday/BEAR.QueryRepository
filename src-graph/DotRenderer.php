<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function addcslashes;
use function implode;
use function sprintf;

/**
 * Graphviz view of the declared graph
 *
 * Arrows point the way they are declared (a page to what it embeds, a write to what it
 * refreshes or purges). For the direction invalidation travels, render with `edge [dir=back]`.
 *
 * @psalm-import-type Node from GraphBuilder
 */
final class DotRenderer
{
    /** @param list<Node> $nodes */
    public function __invoke(array $nodes): string
    {
        $lines = ['digraph qr {', '  rankdir=LR;', '  node [shape=box, fontname="Helvetica"];'];
        foreach ($nodes as $node) {
            $attrs = [];
            if (isset($node['cacheable']) || isset($node['donutCache']) || isset($node['cacheableResponse'])) {
                $attrs[] = 'peripheries=2';
            }

            if (isset($node['dynamic'])) {
                $attrs[] = 'style=dashed';
            }

            $lines[] = sprintf('  %s%s;', self::id($node['uri']), $attrs === [] ? '' : ' [' . implode(', ', $attrs) . ']');
            foreach ($node['methods'] ?? [] as $name => $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $lines[] = sprintf('  %s -> %s [label="embed %s"];', self::id($node['uri']), self::id($embed['uri']), addcslashes($embed['rel'], '"\\'));
                }

                foreach ($method['refresh'] ?? [] as $target) {
                    $lines[] = sprintf('  %s -> %s [label="%s refresh", style=bold];', self::id($node['uri']), self::id($target['uri']), $name);
                }

                foreach ($method['purge'] ?? [] as $target) {
                    $lines[] = sprintf('  %s -> %s [label="%s purge", style=dashed];', self::id($node['uri']), self::id($target['uri']), $name);
                }
            }
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private static function id(string $uri): string
    {
        return '"' . addcslashes($uri, '"\\') . '"';
    }
}
