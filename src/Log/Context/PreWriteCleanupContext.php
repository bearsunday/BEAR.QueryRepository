<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: the invalidate that follows is pre-write cleanup, not a real invalidation.
 *
 * Emitted by a writer (QueryRepository::doPut(), DonutRepository::putStatic()/
 * putDonut()) immediately before it clears the entry it is about to rewrite.
 * The writer knows the purpose of its own invalidate; recording it at the
 * source frees readers from inferring cleanup-vs-invalidation by tag
 * correlation. An invalidate NOT preceded by this marker is a real invalidation.
 */
final class PreWriteCleanupContext extends AbstractContext
{
    public const TYPE = 'pre_write_cleanup';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/pre_write_cleanup.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
