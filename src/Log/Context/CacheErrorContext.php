<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a failure raised on the cache read or write path.
 *
 * Not only the cache pool itself: the write side wraps everything the store
 * performs, so a template that fails to render or a CDN purger that throws is
 * recorded here too. `exceptionClass` says what actually failed — otherwise a
 * cache outage and a rendering bug are the same `operation: "write"` record.
 *
 * A cache_miss after this event means the entry could not be read, not that it
 * was never cached: it separates a degraded cache from a cold one. The operation
 * says which side failed: "read" (the repository get/getDonut call) or "write"
 * (the put/purge call).
 */
final class CacheErrorContext extends AbstractContext
{
    public const TYPE = 'cache_error';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cache_error.json';

    /** @param "read"|"write" $operation */
    public function __construct(
        public readonly string $uri,
        public readonly string $operation,
        public readonly string $error,
        public readonly string|null $exceptionClass = null,
    ) {
    }
}
