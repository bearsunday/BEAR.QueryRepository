<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Swoole\Coroutine;

use Override;

use function class_exists;
use function getenv;

/**
 * Detects the runtimes where a shutdown-time flush is provably the wrong boundary
 *
 * Under PHP-FPM and the CLI a process serves one request, so shutdown is the request's end.
 * RoadRunner loops a worker over many requests and Swoole keeps the process, so shutdown
 * arrives once per worker - far too late, and by then concurrent sessions have been recorded
 * into one shared logger.
 *
 * Only mode is consulted, never capability: ext-swoole is loaded on plenty of machines that
 * serve over FPM, so a loaded extension proves nothing. That leaves one blind spot - a Swoole
 * host whose logger is built at worker boot, outside any coroutine. Such a host binds its own
 * implementation of this interface (or leaves the log module out) rather than being guessed at.
 */
final class HostRuntime implements ConcurrentRuntimeInterface
{
    #[Override]
    public function isConcurrent(): bool
    {
        if (getenv('RR_MODE') !== false) {
            return true; // a RoadRunner worker
        }

        return class_exists(Coroutine::class) && Coroutine::getCid() > 0; // inside a Swoole coroutine
    }
}
