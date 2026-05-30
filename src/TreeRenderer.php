<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function in_array;
use function is_string;

/**
 * The cache dependency graph (the "open"/embed structure) as a `tree`-like outline
 *
 * Built from depends-on events: each parent lists the children it embeds. Reading
 * downward shows what a resource is composed of; reading upward shows the
 * invalidation reach (purging any node closes every ancestor above it).
 */
final class TreeRenderer implements RepositoryLogRendererInterface
{
    /** @param list<array<string, mixed>> $logs */
    #[Override]
    public function render(array $logs): string
    {
        /** @var array<string, list<string>> $children parent => deduped children */
        $children = [];
        $isChild = [];
        foreach ($logs as $log) {
            if (($log['op'] ?? null) !== 'depends-on') {
                continue;
            }

            $parent = $log['parent'] ?? null;
            $child = $log['child'] ?? null;
            if (! is_string($parent) || ! is_string($child)) {
                continue;
            }

            $children[$parent] ??= [];
            if (! in_array($child, $children[$parent], true)) {
                $children[$parent][] = $child;
            }

            $isChild[$child] = true;
        }

        $roots = [];
        foreach (array_keys($children) as $parent) {
            if (! array_key_exists($parent, $isChild)) {
                $roots[] = $parent;
            }
        }

        if ($roots === []) {
            return '(no dependencies)';
        }

        $lines = [];
        $lastRoot = count($roots) - 1;
        foreach ($roots as $i => $root) {
            $lines[] = $root;
            $this->appendChildren($root, $children, '', $lines, [$root]);
            if ($i !== $lastRoot) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, list<string>> $children
     * @param list<string>                $lines
     * @param list<string>                $path     guards against dependency cycles
     */
    private function appendChildren(string $node, array $children, string $prefix, array &$lines, array $path): void
    {
        $kids = $children[$node] ?? [];
        $last = count($kids) - 1;
        foreach ($kids as $i => $kid) {
            $isLast = $i === $last;
            $cycle = in_array($kid, $path, true);
            $lines[] = $prefix . ($isLast ? '└── ' : '├── ') . $kid . ($cycle ? '  (cycle)' : '');
            if ($cycle) {
                continue;
            }

            $this->appendChildren($kid, $children, $prefix . ($isLast ? '    ' : '│   '), $lines, [...$path, $kid]);
        }
    }
}
