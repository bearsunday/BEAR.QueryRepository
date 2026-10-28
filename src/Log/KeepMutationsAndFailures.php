<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;

use function in_array;
use function is_array;
use function is_string;
use function random_int;

/**
 * Keeps the sessions nothing else can account for, drops the rest
 *
 * The line is not failure-vs-success, it is who else knows. A read that fails already reaches the
 * application's own warning channel - both interceptors call `trigger_error()` before degrading -
 * and that channel covers every cause, not just the outage a pool's monitoring would see. The
 * semantic record of it adds a URI and repeats on every request, so keeping those sessions floods
 * the collector during the incident it is supposed to explain, with a fact already reported. A
 * cache that silently did nothing has no other witness at all - `$pool->save()` returning false
 * is discarded by the interceptor, and an invalidation that failed to land shows up later as
 * stale content with no error attached to it.
 *
 * So three kinds of session are kept:
 *
 *  - mutations: a `command` scope, a direct `manual_*` call, or a real tag invalidation. Writes
 *    decide the tags, TTLs and CDN keys every later read repeats.
 *  - effects that did not happen: `saved: false`, `invalidate` with `roPool`/`etagPool`/`cdn`
 *    `failed`, a `manual_*` result of `failed`, and `cache_error{operation: write}` - a store or
 *    an invalidation that was aborted.
 *  - a sample, when a rate is configured.
 *
 * Deliberately not kept: `cache_error{operation: read}` (availability, monitored elsewhere -
 * separating a degraded miss from a cold one is a development-time reading) and every
 * `put_skipped`, which records a store that was never attempted because a rule forbade it - a
 * response code the path will not cache (`#[Cacheable]` skips any non-200, a donut skips 4xx and
 * above), a validator already set, or a page served from its template. Those are decisions, stable
 * for as long as the code and the configuration are, and found once in development rather than per
 * request in production.
 * `saved: false` is the opposite: the store was attempted and the pool refused it. It can repeat
 * per write under a capacity problem, and that is the signal, not noise - a host that needs a
 * ceiling decorates `LogWriterInterface`.
 *
 * A real invalidation is told from pre-write cleanup by the marker the writer records at the
 * source: an `invalidate` is cleanup iff the event immediately before it in the same scope
 * is a `pre_write_cleanup`. Nothing is inferred from tag correlation.
 *
 * The session is read as the decoded public tree - the shape the published schemas describe.
 * A record's `log` (`PublicRecord.log`) interleaves its nested events and opens in record order
 * and degrades to a bare map, so the walk narrows every value it reads and suppresses the mixed
 * reads per method.
 */
final class KeepMutationsAndFailures implements RetentionPolicyInterface
{
    private const MUTATION_SCOPES = ['command', 'manual_store', 'manual_purge', 'manual_invalidate'];
    private const CLEANUP_MARKER = 'pre_write_cleanup';

    /** @param int $sampleRate keep 1 session in N regardless of content; 0 disables sampling */
    public function __construct(private int $sampleRate = 0)
    {
    }

    #[Override]
    public function keeps(LogJson $log): bool
    {
        // The document itself is a node: it carries `log` (like any scope's own children)
        // but no `type`/`context`/`close`, so the mutation-scope and missing-effect checks
        // below are no-ops on it and only `hasRealInvalidation`/`childIsNotable` apply -
        // which is exactly what finds a bare root-level invalidate.
        if ($this->isNotable($log->jsonSerialize())) {
            return true;
        }

        return $this->sampleRate > 0 && random_int(1, $this->sampleRate) === 1;
    }

    /**
     * @param array<mixed, mixed> $node
     *
     * @psalm-suppress MixedAssignment reading a decoded tree: see the class note
     */
    private function isNotable(array $node): bool
    {
        $type = $node['type'] ?? null;
        if (is_string($type) && in_array($type, self::MUTATION_SCOPES, true)) {
            return true;
        }

        if ($this->isMissingEffect($node, $type)) {
            return true;
        }

        if ($this->hasRealInvalidation($node)) {
            return true;
        }

        return $this->childIsNotable($node);
    }

    /**
     * @param array<mixed, mixed> $node
     *
     * @psalm-suppress MixedAssignment reading a decoded tree: see the class note
     */
    private function childIsNotable(array $node): bool
    {
        $children = $node['log'] ?? null;
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child) && $this->isNotable($child)) {
                    return true;
                }
            }
        }

        $close = $node['close'] ?? null;

        return is_array($close) && $this->isNotable($close);
    }

    /**
     * An invalidate is pre-write cleanup only when the marker precedes it among this scope's
     * own events - nested opens interleaved in `log` are skipped, not reset against
     *
     * Adjacency is among events only: a nested open sitting between two events in `children`
     * (semantic-logger#50's single ordered list) must not break the sequence, the same as it
     * could never appear inside the old shape's separate `events` list.
     *
     * @param array<mixed, mixed> $node
     *
     * @psalm-suppress MixedAssignment reading a decoded tree: see the class note
     */
    private function hasRealInvalidation(array $node): bool
    {
        $children = $node['log'] ?? null;
        if (! is_array($children)) {
            return false;
        }

        $previous = null;
        foreach ($children as $child) {
            if (! is_array($child) || ! $this->isPoint($child)) {
                continue; // a nested open, not this scope's own event
            }

            // is_array() narrows rather than guards: a point in the public tree is always a map
            $type = $child['type'] ?? null;
            if ($type === 'invalidate' && $previous !== self::CLEANUP_MARKER) {
                return true;
            }

            $previous = is_string($type) ? $type : null;
        }

        return false;
    }

    /**
     * A record with none of `close`/`log`/`openId` is an event recorded in the active scope
     *
     * @param array<mixed, mixed> $record
     */
    private function isPoint(array $record): bool
    {
        return ! isset($record['close']) && ! isset($record['log']) && ! isset($record['openId']);
    }

    /**
     * An effect that was intended and did not happen
     *
     * @param array<mixed, mixed> $node
     */
    private function isMissingEffect(array $node, mixed $type): bool
    {
        $context = $node['context'] ?? null;
        if (! is_array($context)) {
            return false;
        }

        if (($context['saved'] ?? null) === false || ($context['result'] ?? null) === 'failed') {
            return true;
        }

        // Every per-target outcome is a self-describing status word: `roPool`/`etagPool` are
        // invalidated|failed and `cdn` is purged|failed|skipped - none of them are booleans.
        foreach (['cdn', 'roPool', 'etagPool'] as $target) {
            if (($context[$target] ?? null) === 'failed') {
                return true;
            }
        }

        // A read that threw is availability, and its monitoring saw it first; a write that threw
        // aborted a store or an invalidation, which nothing else records.
        return $type === 'cache_error' && ($context['operation'] ?? null) === 'write';
    }
}
