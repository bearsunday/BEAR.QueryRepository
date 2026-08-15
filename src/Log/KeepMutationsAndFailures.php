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
 * Keeps the sessions that can explain an incident, drops the rest
 *
 * Three kinds of session are kept:
 *
 *  - mutations: a command scope or a direct `manual_*` call, plus any real tag invalidation.
 *    Writes decide the tags, the TTL and the CDN keys that every later read merely repeats,
 *    and a stale-serving incident is diagnosed from the tags a purge reached.
 *  - failures: the cache path fails without throwing - `put()`'s return value is discarded
 *    by the interceptor and `saveEtag()` does not even return it - so `saved: false`,
 *    `cache_error`, `cdn: failed` and `put_skipped{error-code}` exist in the log alone.
 *  - a sample, when a rate is configured: a baseline for what the tags and TTLs really are.
 *
 * A real invalidation is told from pre-write cleanup by the marker the writer records at the
 * source: an `invalidate` is cleanup iff the event immediately before it in the same scope
 * is a `pre_write_cleanup`. Nothing is inferred from tag correlation.
 *
 * The session is read as the decoded public tree rather than the logger's own entry objects,
 * because that is the shape the published schemas describe. Its type degrades to a bare map at
 * nested opens (`PublicOpenEntry.open` is `list<JsonMap>`), so the walk narrows each value it
 * reads and the mixed reads are suppressed per method.
 */
final class KeepMutationsAndFailures implements RetentionPolicyInterface
{
    private const MUTATION_SCOPES = ['command', 'manual_store', 'manual_purge', 'manual_invalidate'];
    private const FAILURE_TYPES = ['cache_error', 'semantic_logger_error'];
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

        if ($this->isFailure($node, $type)) {
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

        $close = $node['close'] ?? null;

        return is_array($close) && $this->isNotable($close);
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

    /** @param array<mixed, mixed> $node */
    private function isFailure(array $node, mixed $type): bool
    {
        if (is_string($type) && in_array($type, self::FAILURE_TYPES, true)) {
            return true;
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

        return $type === 'put_skipped' && ($context['reason'] ?? null) === 'error-code';
    }
}
