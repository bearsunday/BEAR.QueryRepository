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
 * The line is not failure-vs-success, it is who else knows: a read failure already reaches the
 * application's own warning channel, and a rule-based `put_skipped` is a decision stable with the
 * code. `put_skipped{reason: unserializable}` is kept regardless, since a body `serialize()`
 * refused is a defect, not a rule, and dropping it would defeat `SkipBodySerializationError`'s
 * point of making that defect visible in production.
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
     * @psalm-suppress MixedAssignment reading the session as the decoded public tree
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
     * @psalm-suppress MixedAssignment reading the session as the decoded public tree
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
     * @psalm-suppress MixedAssignment reading the session as the decoded public tree
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

        // Every other put_skipped reason is a rule the code enforces on purpose; this one is a
        // body serialize() refused at runtime - a defect, the same kind of fact as a write that threw.
        if ($type === 'put_skipped' && ($context['reason'] ?? null) === 'unserializable') {
            return true;
        }

        // A read that threw is availability, and its monitoring saw it first; a write that threw
        // aborted a store or an invalidation, which nothing else records.
        return $type === 'cache_error' && ($context['operation'] ?? null) === 'write';
    }
}
