<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Open: a conditional request presented a validator (If-None-Match).
 *
 * The scope closes with the ETag pool's answer as cache_hit/cache_miss at layer
 * `etag`: a hit is the 304 decision - the whole request is served without running
 * the resource, the cache event no other scope can show. Recorded by the
 * HttpCacheInterface implementations at the transfer boundary; a request without
 * the header presents nothing, so nothing is recorded.
 */
final class ConditionalRequestContext extends AbstractContext
{
    public const TYPE = 'conditional_request';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/conditional_request.json';

    public function __construct(
        public readonly string $ifNoneMatch,
    ) {
    }
}
