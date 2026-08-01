<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Open/close sentinel: the previous logging session was broken and discarded.
 *
 * Emitted by SafeSemanticLogger when the delegate's flush() throws (e.g. a LIFO
 * open/close violation left the session unclosable). The flush that contains
 * this scope contains ONLY it — the wiped session's records are gone, so the
 * cache activity of that window is unknown, not "no cache activity". The next
 * session logs normally on a fresh delegate.
 */
final class LogSessionBrokenContext extends AbstractContext
{
    public const TYPE = 'log_session_broken';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/log_session_broken.json';

    public function __construct(
        public readonly string $reason,
    ) {
    }
}
