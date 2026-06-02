<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Open: an application-initiated (manual) tag invalidation.
 *
 * Emitted only when invalidateTags() is top-level (not nested inside a request
 * GET or a write command). A direct invalidation has no enclosing scope, so its
 * outcome event would be dropped at flush; rooting it here keeps it visible. The
 * outcome is recorded on the close InvalidateContext.
 */
final class ManualInvalidateContext extends AbstractContext
{
    public const TYPE = 'manual_invalidate';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/manual_invalidate.json';

    /** @param list<string> $tags */
    public function __construct(
        public readonly array $tags,
    ) {
    }
}
