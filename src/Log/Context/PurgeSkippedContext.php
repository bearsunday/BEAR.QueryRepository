<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a write left its own key alone, because its query does not hold every parameter its onGet() requires.
 *
 * A POST that creates an entity has no id yet, so it addresses no stored GET. Its #[Purge]/#[Refresh] still run.
 */
final class PurgeSkippedContext extends AbstractContext
{
    public const TYPE = 'purge_skipped';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/purge_skipped.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
