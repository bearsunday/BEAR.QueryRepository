<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Close/event: a cache lookup hit at the given layer.
 */
final class CacheHitContext extends AbstractContext
{
    public const TYPE = 'cache_hit';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cache_hit.json';

    public function __construct(
        public readonly string $layer,
    ) {
    }
}
