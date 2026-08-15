<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use JsonSerializable;
use Koriym\SemanticLogger\AbstractContext;
use Override;

/**
 * Close of a manual_invalidate scope: whether the invalidation took everywhere.
 *
 * The nested invalidate event carries the per-target detail (roPool/etagPool/cdn);
 * this close is the one-word verdict, like its manual_store/manual_purge siblings.
 * Keeping the detail on the event also keeps the llms reading rule intact: every
 * invalidation - manual or framework-driven - is findable among the events, so
 * correlating save_* tags with invalidate tags misses nothing.
 */
final class ManualInvalidateResultContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'manual_invalidate_result';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/manual_invalidate_result.json';

    public function __construct(
        public readonly bool $invalidated,
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['result' => $this->invalidated ? 'invalidated' : 'failed'];
    }
}
