<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

/**
 * Resolves the session a facade singleton records into
 *
 * SafeSemanticLogger is bound once per process, like ServerContextInterface and
 * ConcurrentRuntimeInterface - but unlike server context or runtime detection, what it
 * touches on every call (open scope depth, the delegate logger) is per request, not per
 * process. The default implementation, ProcessSession, answers with the same session for
 * the whole process, which is correct exactly where PHP-FPM and the CLI are correct: one
 * process serves one request. A concurrent host (a Swoole coroutine, a RoadRunner worker
 * looping over requests) binds its own implementation keyed by that host's request
 * context, the same way it would bind ServerContextInterface or ConcurrentRuntimeInterface.
 * Binding a keyed store alone does not make recording safe on such a host: current() only
 * stops two requests from sharing one session, it does not drain either one, so the sink
 * bound alongside it (see LogSinkInterface) must also flush at that host's actual
 * request-end boundary, not at process shutdown.
 */
interface SessionStoreInterface
{
    /** The session of the request in progress, created on first use */
    public function current(): Session;

    /** Drop the current request's session; called after it has been flushed */
    public function forget(): void;
}
