<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: the cache layer itself errored (e.g. cache server down).
 *
 * Distinguishes a degraded cache from a cold one: a cache_miss after this
 * event means the entry could not be read, not that it was never cached.
 */
final class CacheErrorContext extends AbstractContext
{
    public const TYPE = 'cache_error';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cache_error.json';

    public function __construct(
        public readonly string $uri,
        public readonly string $error,
    ) {
    }
}
