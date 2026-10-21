<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\SemanticLogValidator;
use RuntimeException;

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
use function unlink;

use const JSON_UNESCAPED_SLASHES;

/**
 * Helpers for asserting against the SemanticLogger tree produced by a scenario
 *
 * flush() drains the shared logger, so call the helpers once per scenario and
 * reuse the returned tree array for further structural assertions. All tree
 * walking guards against mixed shapes so callers get clean typed results.
 *
 * Every level is one `log` list, record order, points and nested scopes
 * interleaved (`PublicRecord.log`). A node is a scope if it carries `log` or
 * `close`; a point (event) carries neither, nor `openId`; a root orphan close
 * carries only `openId`.
 */
trait SemanticLogTreeTrait
{
    /**
     * Flush the logger and assert every entry validates against docs/schemas/context
     *
     * Since koriym/semantic-logger 0.9 the logger never throws — protocol misuse is
     * recorded in-band as core `semantic_logger_error` diagnostics — so the gate lives
     * on the reading side: a scenario that leaves a scope unclosed or closes out of
     * order fails here instead of passing silently.
     *
     * @return array<string, mixed> the flushed log tree, for further assertions
     */
    private function flushAndValidate(SemanticLoggerInterface $logger): array
    {
        return $this->flushAndValidateLog($logger, true);
    }

    /**
     * Same, for a scenario that deliberately misuses the logging protocol
     *
     * The expected diagnostics still have to validate — against the core schemas
     * bundled with koriym/semantic-logger, which the validator resolves itself.
     *
     * @return array<string, mixed> the flushed log tree, for further assertions
     */
    private function flushAndValidateWithDiagnostics(SemanticLoggerInterface $logger): array
    {
        return $this->flushAndValidateLog($logger, false);
    }

    /** @return array<string, mixed> */
    private function flushAndValidateLog(SemanticLoggerInterface $logger, bool $failOnDiagnostics): array
    {
        /** @var array<string, mixed> $tree */
        $tree = $logger->flush()->toArray();
        $file = (string) tempnam(sys_get_temp_dir(), 'slog');
        file_put_contents($file, (string) json_encode($tree, JSON_UNESCAPED_SLASHES));
        $schemaDir = dirname(__DIR__) . '/docs/schemas/context';

        $failure = null;
        ob_start();
        try {
            (new SemanticLogValidator())->validate($file, $schemaDir, $failOnDiagnostics);
        } catch (RuntimeException $e) {
            $failure = $e;
        } finally {
            $report = (string) ob_get_clean();
            unlink($file);
        }

        if ($failure !== null) {
            self::fail($failure->getMessage() . "\n" . $report); // the validator's own per-entry report
        }

        return $tree;
    }

    /**
     * Collect the `type` of every node (scope, point, close) in the tree, depth-first
     *
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function collectTypes(array $tree): array
    {
        $types = [];
        self::walk($tree['log'] ?? [], $types);

        return $types;
    }

    /**
     * Maximum nesting depth of scopes (a chain of N nested scopes returns N)
     *
     * @param array<string, mixed> $tree
     */
    private static function maxOpenDepth(array $tree): int
    {
        return self::depth($tree['log'] ?? []);
    }

    /**
     * JSON of the first scope's context whose type matches, or null if absent
     *
     * Note: only matches scope nodes (`log` or `close` present); it does not match
     * types that live under a point or a close. Sufficient for scope types such as
     * `command`.
     *
     * @param array<string, mixed> $tree
     */
    private static function contextJsonOf(array $tree, string $type): string|null
    {
        return self::findContextJson($tree['log'] ?? [], $type);
    }

    /**
     * JSON of the first event (point) context whose type matches, or null if absent
     *
     * Searches points nested under scopes and top-level points alike.
     *
     * @param array<string, mixed> $tree
     */
    private static function eventContextJsonOf(array $tree, string $type): string|null
    {
        return self::eventContextsJsonOf($tree, $type)[0] ?? null;
    }

    /**
     * JSON of the first close context whose type matches, or null if absent
     *
     * @param array<string, mixed> $tree
     */
    private static function closeContextJsonOf(array $tree, string $type): string|null
    {
        return self::findCloseContextJson($tree['log'] ?? [], $type);
    }

    /**
     * JSON of every event (point) context whose type matches, depth-first, optionally for one `uri` only
     *
     * One type can be emitted for several URIs in one session - a parent and the children
     * it embeds both `save_etag` - so a tag assertion has to name whose event it reads.
     *
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function eventContextsJsonOf(array $tree, string $type, string|null $uri = null): array
    {
        $contexts = [];
        self::collectEventContextsJson($tree['log'] ?? [], $type, $uri, $contexts);

        return $contexts;
    }

    /**
     * Close of the first `$openType` scope opened for `$uri`, as `[type, context JSON]`
     *
     * @param array<string, mixed> $tree
     *
     * @return array{string, string}|null
     */
    private static function scopeCloseOf(array $tree, string $openType, string $uri): array|null
    {
        $scope = self::findScope($tree['log'] ?? [], $openType, $uri);
        $close = $scope === null ? null : $scope['close'] ?? null;
        if (! is_array($close) || ! isset($close['type']) || ! is_string($close['type'])) {
            return null;
        }

        return [$close['type'], (string) json_encode($close['context'] ?? null, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * Point `type`s of the first `$openType` scope opened for `$uri`, that scope's own points only
     *
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function scopeEventTypesOf(array $tree, string $openType, string $uri): array
    {
        $scope = self::findScope($tree['log'] ?? [], $openType, $uri);
        $types = [];
        if ($scope !== null) {
            self::walkEvents($scope['log'] ?? [], $types);
        }

        return $types;
    }

    /**
     * Every matching point's context, depth-first through every node - a nested scope does
     * not stop the search, it is descended into via its own `log`
     *
     * @param mixed        $nodes
     * @param list<string> $contexts
     */
    private static function collectEventContextsJson(mixed $nodes, string $type, string|null $uri, array &$contexts): void
    {
        if (! is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (self::isPoint($node) && ($node['type'] ?? null) === $type) {
                $context = $node['context'] ?? null;
                if ($uri === null || (is_array($context) && ($context['uri'] ?? null) === $uri)) {
                    $contexts[] = (string) json_encode($context, JSON_UNESCAPED_SLASHES);
                }
            }

            self::collectEventContextsJson($node['log'] ?? [], $type, $uri, $contexts);
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function findScope(mixed $nodes, string $openType, string $uri): array|null
    {
        if (! is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! is_array($node) || ! self::isScope($node)) {
                continue;
            }

            $context = $node['context'] ?? null;
            if (($node['type'] ?? null) === $openType && is_array($context) && ($context['uri'] ?? null) === $uri) {
                return $node;
            }

            $found = self::findScope($node['log'] ?? [], $openType, $uri);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The `type` of every bare point directly at the session root, in emission order
     *
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function rootPointTypes(array $tree): array
    {
        $types = [];
        self::walkEvents($tree['log'] ?? [], $types);

        return $types;
    }

    /**
     * Event `type` sequences of every scope in the tree (root included), depth-first
     *
     * Each list is one scope's own direct points in emission order, nested scopes
     * filtered out (`walkEvents` does not recurse): points within one scope are
     * time-ordered relative to each other and to a nested scope's own start, which
     * the shared `log` list now preserves. Lets tests assert same-scope adjacency
     * rules such as "a cleanup invalidate is immediately preceded by its
     * pre_write_cleanup marker".
     *
     * @param array<string, mixed> $tree
     *
     * @return list<list<string>>
     */
    private static function scopeEventTypeSequences(array $tree): array
    {
        $sequences = [];
        self::collectScopeEventSequences($tree['log'] ?? [], $sequences);

        return $sequences;
    }

    /**
     * @param mixed              $nodes one scope's own `log` list
     * @param list<list<string>> $sequences
     */
    private static function collectScopeEventSequences(mixed $nodes, array &$sequences): void
    {
        if (! is_array($nodes)) {
            return;
        }

        $types = [];
        self::walkEvents($nodes, $types);
        if ($types !== []) {
            $sequences[] = $types;
        }

        foreach ($nodes as $node) {
            if (is_array($node) && self::isScope($node)) {
                self::collectScopeEventSequences($node['log'] ?? [], $sequences);
            }
        }
    }

    /**
     * Depth-first type collection: each node's own type, then its children in record
     * order, then its close type if any (a point has neither children nor close, so
     * it naturally contributes only its own type)
     *
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

            self::walk($node['log'] ?? [], $types);

            $close = $node['close'] ?? null;
            if (is_array($close) && isset($close['type']) && is_string($close['type'])) {
                $types[] = $close['type'];
            }
        }
    }

    /**
     * Direct point children only, in record order; does not recurse into nested
     * scopes - an interleaved scope does not appear here and does not break the
     * adjacency it preserves
     *
     * @param mixed        $nodes
     * @param list<string> $types
     */
    private static function walkEvents(mixed $nodes, array &$types): void
    {
        if (! is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (is_array($node) && self::isPoint($node) && isset($node['type']) && is_string($node['type'])) {
                $types[] = $node['type'];
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
            if (! is_array($node) || ! self::isScope($node)) {
                continue;
            }

            $max = max($max, 1 + self::depth($node['log'] ?? []));
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

            if (self::isScope($node) && ($node['type'] ?? null) === $type) {
                return (string) json_encode($node['context'] ?? null, JSON_UNESCAPED_SLASHES);
            }

            $found = self::findContextJson($node['log'] ?? [], $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function findCloseContextJson(mixed $nodes, string $type): string|null
    {
        if (! is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $close = $node['close'] ?? null;
            if (is_array($close) && ($close['type'] ?? null) === $type) {
                return (string) json_encode($close['context'] ?? null, JSON_UNESCAPED_SLASHES);
            }

            $found = self::findCloseContextJson($node['log'] ?? [], $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * A scope carries `log` (even `[]`, unclosed) or `close` (completed)
     *
     * @param array<array-key, mixed> $node
     */
    private static function isScope(array $node): bool
    {
        return isset($node['log']) || isset($node['close']);
    }

    /**
     * A point (event) carries none of `log`/`close`/`openId`
     *
     * @param array<array-key, mixed> $node
     */
    private static function isPoint(array $node): bool
    {
        return ! isset($node['log']) && ! isset($node['close']) && ! isset($node['openId']);
    }
}
