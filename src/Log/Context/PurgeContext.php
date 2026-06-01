<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: an explicit purge of a URI was requested.
 */
final class PurgeContext extends AbstractContext
{
    public const TYPE = 'purge';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/purge.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
