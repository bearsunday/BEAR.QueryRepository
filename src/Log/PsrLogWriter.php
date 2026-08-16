<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Hands a kept session to the application's PSR-3 logger
 *
 * The adapter exists so a session can reach monolog, syslog or whatever the host already runs,
 * while the tree stays the payload rather than the message: PSR-3 carries strings, and a tree
 * flattened by a LineFormatter is a log that lies. It is passed under `log` in the context, so
 * a JSON formatter preserves it verbatim - a host whose formatter would drop context should
 * write with LogFileWriter or LogStreamWriter instead.
 *
 * Severity is configuration, not derivation. Whether this session was worth keeping was already
 * decided by the retention policy, on content; re-deciding it here as a level would give the
 * handler a second, invisible filter that can drop what the policy chose to keep.
 *
 * This is the one writer that breaks LogWriterInterface's serializable rule: it holds the host's
 * logger, and a Monolog instance carrying closures (processors, a formatter, an exception
 * handler) cannot be serialized - which would make the whole compiled app graph unserializable,
 * since SafeSemanticLogger carries the sink and the sink carries the writer. Use it where the
 * injector is not serialized, or where the bound logger is itself serializable; a compiled app
 * that serializes its graph should write with LogFileWriter or LogStreamWriter and ship the file.
 */
final class PsrLogWriter implements LogWriterInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $level = LogLevel::INFO,
        private string $message = 'query_repository_log',
    ) {
    }

    #[Override]
    public function write(LogJson $log): void
    {
        if ($log->open === [] && $log->events === []) {
            return; // nothing was recorded
        }

        $this->logger->log($this->level, $this->message, ['log' => $log->jsonSerialize()]);
    }
}
