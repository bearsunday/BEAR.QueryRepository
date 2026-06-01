<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\SemanticLogValidator;

use function dirname;
use function file_put_contents;
use function is_array;
use function is_string;
use function json_encode;
use function max;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_UNESCAPED_SLASHES;

/**
 * Helpers for asserting against the SemanticLogger tree produced by a scenario
 *
 * flush() drains the shared logger, so call the helpers once per scenario and
 * reuse the returned tree array for further structural assertions. All tree
 * walking guards against mixed shapes so callers get clean typed results.
 */
trait SemanticLogTreeTrait
{
    /**
     * Flush the logger and assert every entry validates against docs/schemas/context
     *
     * @return array<string, mixed> the flushed log tree, for further assertions
     */
    private function flushAndValidate(SemanticLoggerInterface $logger): array
    {
        /** @var array<string, mixed> $tree */
        $tree = $logger->flush()->toArray();
        $open = $tree['open'] ?? [];
        if (! is_array($open) || $open === []) {
            return $tree; // nothing was logged in this scenario; nothing to validate
        }

        $file = (string) tempnam(sys_get_temp_dir(), 'slog');
        file_put_contents($file, (string) json_encode($tree, JSON_UNESCAPED_SLASHES));
        $schemaDir = dirname(__DIR__) . '/docs/schemas/context';

        ob_start();
        try {
            (new SemanticLogValidator())->validate($file, $schemaDir);
        } finally {
            ob_get_clean();
        }

        return $tree;
    }

    /**
     * Collect the `type` of every node (open, events, close) in the tree, depth-first
     *
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function collectTypes(array $tree): array
    {
        $types = [];
        self::walk($tree['open'] ?? [], $types);
        self::walkEvents($tree['events'] ?? [], $types);

        return $types;
    }

    /**
     * Maximum nesting depth of open scopes (a chain of N nested opens returns N)
     *
     * @param array<string, mixed> $tree
     */
    private static function maxOpenDepth(array $tree): int
    {
        return self::depth($tree['open'] ?? []);
    }

    /**
     * JSON of the first node's context whose type matches, or null if absent
     *
     * Note: only descends `open` scopes; it does not match types that live under
     * `events` or a `close`. Sufficient for open-scope types such as `command`.
     *
     * @param array<string, mixed> $tree
     */
    private static function contextJsonOf(array $tree, string $type): string|null
    {
        return self::findContextJson($tree['open'] ?? [], $type);
    }

    /**
     * @param mixed        $nodes
     * @param list<string> $types
     */
    private static function walk(mixed $nodes, array &$types): void
    {
        if (! is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['type']) && is_string($node['type'])) {
                $types[] = $node['type'];
            }

            self::walkEvents($node['events'] ?? [], $types);
            $close = $node['close'] ?? null;
            if (is_array($close) && isset($close['type']) && is_string($close['type'])) {
                $types[] = $close['type'];
            }

            self::walk($node['open'] ?? [], $types);
        }
    }

    /**
     * @param mixed        $events
     * @param list<string> $types
     */
    private static function walkEvents(mixed $events, array &$types): void
    {
        if (! is_array($events)) {
            return;
        }

        foreach ($events as $event) {
            if (is_array($event) && isset($event['type']) && is_string($event['type'])) {
                $types[] = $event['type'];
            }
        }
    }

    private static function depth(mixed $nodes): int
    {
        if (! is_array($nodes) || $nodes === []) {
            return 0;
        }

        $max = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $max = max($max, 1 + self::depth($node['open'] ?? []));
        }

        return $max;
    }

    private static function findContextJson(mixed $nodes, string $type): string|null
    {
        if (! is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === $type) {
                return (string) json_encode($node['context'] ?? null, JSON_UNESCAPED_SLASHES);
            }

            $found = self::findContextJson($node['open'] ?? [], $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
