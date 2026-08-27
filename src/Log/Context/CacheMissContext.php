<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Close/event: a cache lookup miss at the given layer.
 */
final class CacheMissContext extends AbstractContext
{
    public const TYPE = 'cache_miss';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cache_miss.json';

    /**
     * @param float|null $durationMs wall time from the scope opening to this close. Null on a bare
     *                               event: an inner lookup has no scope, so it has no duration to
     *                               report and a zero would read as "instant".
     */
    public function __construct(
        public readonly string $layer,
        public readonly float|null $durationMs = null,
    ) {
    }
}
