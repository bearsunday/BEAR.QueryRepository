<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

/**
 * A cache store failure, which the cache interceptors degrade to a warning instead of failing the request
 *
 * A CDN purge failure and a hole that throws while a cached page is recomposed arrive as this
 * type too. The cause is the previous exception.
 */
final class CacheStoreException extends RuntimeException
{
}
