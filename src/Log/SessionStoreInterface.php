<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

/**
 * Resolves the session of the request in progress for the process-wide SafeSemanticLogger
 *
 * ProcessSession, the default, is right where one process serves one request. A concurrent
 * host (Swoole coroutine, RoadRunner worker) binds an implementation keyed by its request
 * context, as it does for ServerContextInterface. The store only keeps requests from sharing a
 * session; the host's LogSinkInterface still has to flush at that host's request end.
 *
 * Implementations are serialized with the compiled app and must carry configuration only: a
 * session that crossed that boundary would hand one request's depth and log to the next.
 */
interface SessionStoreInterface
{
    /** The session of the request in progress, created on first use */
    public function current(): Session;

    /** Drop the current request's session; called after it has been flushed */
    public function forget(): void;
}
