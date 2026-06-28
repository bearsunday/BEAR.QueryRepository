<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Throwable;

/**
 * Outcome of a tag invalidation across the resource pool, the ETag pool, and the CDN
 *
 * ResourceStorage returns this instead of a bare bool so the logging decorator can
 * report the per-target outcome (roPool / etagPool / cdn) that is computed by the
 * side-effecting pool operations and is not derivable from the inputs alone. The
 * CDN purge error is carried (not thrown) so the decorator can log the failure
 * before re-throwing it (fail-closed).
 */
final class InvalidateResult
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly array $tags,
        public readonly bool $roInvalidated,
        public readonly bool $etagInvalidated,
        public readonly Throwable|null $cdnError = null,
    ) {
    }

    /**
     * Whether both local pools were invalidated (the legacy bool return value)
     */
    public function isInvalidated(): bool
    {
        return $this->roInvalidated && $this->etagInvalidated;
    }
}
