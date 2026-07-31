<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: the put was intentionally skipped after a miss.
 *
 * Records why a miss is not followed by save_* events — without it the log
 * looks like a lost write. The reason is self-describing: the response already
 * carries an ETag (etag-present) or is an error response (error-code).
 */
final class PutSkippedContext extends AbstractContext
{
    public const TYPE = 'put_skipped';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/put_skipped.json';

    public function __construct(
        public readonly string $uri,
        public readonly string $reason,
    ) {
    }
}
