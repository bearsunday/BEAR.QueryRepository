<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: the cache backend refused an operation and the adapter swallowed it.
 *
 * `symfony/cache` adapters never throw at the application: a store that cannot be reached
 * produces a miss on the read side and `false` on the write side. Nothing this package can catch
 * ever happens, so a dead store reads exactly like a cold one - which is why the adapter's own
 * report is recorded here. What is known at this point is the pool key, not the resource URI.
 */
final class PoolErrorContext extends AbstractContext
{
    public const TYPE = 'pool_error';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/pool_error.json';

    /** @param 'read'|'write'|'unknown' $operation */
    public function __construct(
        public readonly string $key,
        public readonly string $operation,
        public readonly string $error,
        public readonly string $exceptionClass,
    ) {
    }
}
