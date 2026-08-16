<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a miss was not followed by a put.
 *
 * Records the fact that no store happened after the miss and why (`reason`):
 * the response already carries an ETag (etag-present), is an error response
 * (error-code, with the actual response `code`), or the entry kind is not
 * whole-content cacheable (not-cacheable). Whether skipping the put is correct
 * is for the reader to judge from the recorded reason and code.
 */
final class PutSkippedContext extends AbstractContext
{
    public const TYPE = 'put_skipped';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/put_skipped.json';

    /** @param "etag-present"|"error-code"|"not-cacheable" $reason */
    public function __construct(
        public readonly string $uri,
        public readonly string $reason,
        public readonly int|null $code = null,
    ) {
    }
}
