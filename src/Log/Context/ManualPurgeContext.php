<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Open: an application-initiated (manual) purge of a URI.
 *
 * Emitted only when the purge is top-level (not nested inside a request GET or a
 * write command), so a deliberate cache bust stands out from automatic invalidation.
 */
final class ManualPurgeContext extends AbstractContext
{
    public const TYPE = 'manual_purge';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/manual_purge.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
