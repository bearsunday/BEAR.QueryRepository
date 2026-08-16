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
 * The line is not failure-vs-success, it is who else knows. A pool that is down is an
 * availability event: its own monitoring sees it first, and the app-side evidence
 * (`cache_error{read}`) repeats on every request, so keeping those sessions would flood the
 * collector during the incident it is supposed to explain, with facts already held elsewhere. A
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
 *    an invalidation that was aborted. `semantic_logger_error` joins them because it means the
 *    records themselves may be incomplete.
 *  - a sample, when a rate is configured.
 *
 * Deliberately not kept: `cache_error{operation: read}` (availability, monitored elsewhere - the
 * "degraded, not cold" reading is a development-time one) and every `put_skipped`, which records a
 * store that was never attempted because a rule forbade it - a response code the path will not
 * cache (`#[Cacheable]` skips any non-200, a donut skips 4xx and above), a validator already set,
 * or a page served from its template. Those are decisions, stable for as long as the code and the
 * configuration are, and found once in development rather than per request in production.
 * `saved: false` is the opposite: the store was attempted and the pool refused it. It can repeat
 * per write under a capacity problem, and that is the signal, not noise - a host that needs a
 * ceiling decorates `LogWriterInterface`.
 *
 * A real invalidation is told from pre-write cleanup by the marker the writer records at the
 * source: an `invalidate` is cleanup iff the event immediately before it in the same scope
 * is a `pre_write_cleanup`. Nothing is inferred from tag correlation.
 *
 * The session is read as the decoded public tree - the shape the published schemas describe.
 * That type degrades to a bare map at nested opens (`PublicOpenEntry.open` is `list<JsonMap>`),
 * so the walk narrows every value it reads and suppresses the mixed reads per method.
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
        foreach (['open', 'events'] as $key) {
            $children = $node[$key] ?? null;
            if (! is_array($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (is_array($child) && $this->isNotable($child)) {
                    return true;
                }
            }
        }

        return $this->closeIsNotable($node['close'] ?? null);
    }

    /** @psalm-suppress MixedAssignment reading a decoded tree: see the class note */
    private function closeIsNotable(mixed $close): bool
    {
        if (! is_array($close)) {
            return false;
        }

        // `close` is one entry on a scope, but a list of orphan closes at the root
        foreach (isset($close['type']) ? [$close] : $close as $entry) {
            if (is_array($entry) && $this->isNotable($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An invalidate is pre-write cleanup only when the marker precedes it in this scope
     *
     * @param array<mixed, mixed> $node
     *
     * @psalm-suppress MixedAssignment reading a decoded tree: see the class note
     */
    private function hasRealInvalidation(array $node): bool
    {
        $events = $node['events'] ?? null;
        if (! is_array($events)) {
            return false;
        }

        $previous = null;
        foreach ($events as $event) {
            // is_array() narrows rather than guards: an event in the public tree is always a map
            $type = is_array($event) ? $event['type'] ?? null : null;
            if ($type === 'invalidate' && $previous !== self::CLEANUP_MARKER) {
                return true;
            }

            $previous = is_string($type) ? $type : null;
        }

        return false;
    }

    /**
     * An effect that was intended and did not happen
     *
     * @param array<mixed, mixed> $node
     */
    private function isMissingEffect(array $node, mixed $type): bool
    {
        if ($type === 'semantic_logger_error') {
            return true; // the records themselves may be incomplete
        }

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
