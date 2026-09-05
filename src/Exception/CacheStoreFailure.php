<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use Throwable;

/**
 * The cache store failed - the one failure a cache path is allowed to swallow (issue #190)
 *
 * Interceptors degrade rather than fail a request whose response is already correct, and this
 * class is what makes that decision safe to write down. Catching `Throwable` there swallowed
 * everything a write touches: a renderer with a bug, a logic error introduced later. Only what
 * the storage boundary raises arrives as this type, so an interceptor catches this and lets the
 * rest travel.
 *
 * The cause is kept: the log names the throwable that actually failed (a Redis timeout, a
 * marshalling error), not this wrapper.
 *
 * A CDN purge failure carries this type too, and how far it travels depends on who asked: a
 * command or a direct call has no interceptor, so it reaches the caller and the write is not
 * reported as done. The automatic write inside a GET is caught and degraded - that request
 * changed nothing, so nothing at an edge became stale by it.
 */
final class CacheStoreFailure extends RuntimeException
{
    public static function from(Throwable $e): self
    {
        return new self($e->getMessage(), (int) $e->getCode(), $e);
    }
}
