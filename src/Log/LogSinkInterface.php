<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLoggerInterface;

/**
 * Owns when a session is flushed
 *
 * Flushing belongs to the end of the request, which is not the same place as the end of any
 * method: rendering happens inside `ResourceObject::transfer()`, so cache reads are still
 * being recorded while the response is written, and a 304 answer never reaches that method
 * at all - `HttpCacheInterface::transfer()` sets the status and the caller stops. A sink is
 * armed instead, once per process, and fires however the request ends.
 *
 * Implementations must be serializable (the compiled app carries them between requests) and
 * must arm again after unserialize, which is the only hook that runs on every request.
 */
interface LogSinkInterface
{
    /** Register the flush for the end of this request; calling it twice in a process is a no-op */
    public function arm(SemanticLoggerInterface $logger): void;

    /** Flush the session and hand it to the writer - what the armed callback does */
    public function flush(SemanticLoggerInterface $logger): void;
}
