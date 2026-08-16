<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;

/**
 * Depth-tracking decorator over the total (never-throwing) SemanticLogger
 *
 * Since koriym/semantic-logger 0.9 the core logger is a total function: it never
 * throws, and records protocol misuse (LIFO violations, sessions left unclosed at
 * flush) as in-band `semantic_logger_error` diagnostics. The broken-flag /
 * sentinel machinery this class used to carry is therefore gone — an exception
 * guard around a delegate that cannot throw is dead code, and silently swallowing
 * failures would hide exactly what the diagnostics exist to show.
 *
 * What remains are the two responsibilities orthogonal to totality:
 *
 *  - Depth tracking: lets callers distinguish an application-initiated (manual)
 *    operation from one nested inside a framework scope (a request GET or a
 *    write command). Best-effort: a LIFO violation desynchronises the count from
 *    the core stack, which is why depth tracking is scheduled to move into the
 *    core itself (semantic-logger 1.0).
 *  - Serialization boundary: a compiled app serializes the injector between
 *    requests; session state never crosses that boundary — unserialize()
 *    restarts with a fresh session. It is also the only hook that runs on every
 *    request, so the flush sink (when one is bound) is armed from there.
 */
final class SafeSemanticLogger implements SemanticLoggerInterface, TopLevelAwareInterface
{
    private int $depth = 0;

    public function __construct(
        private SemanticLoggerInterface $logger,
        private LogSinkInterface|null $sink = null,
    ) {
        $this->armOrFallSilent();
    }

    /**
     * Record only while something will drain the session
     *
     * A sink that refuses this host (a concurrent runtime, where shutdown arrives once per
     * worker) leaves no drain at all, and an undrained session grows for the life of the
     * process. Recording into it would trade a log nobody reads for memory, so the delegate
     * becomes the no-op logger instead. No sink at all is a different case: the caller flushes
     * it (tests, demos, a host with its own lifecycle), so recording stays on.
     */
    private function armOrFallSilent(): void
    {
        if ($this->sink === null || $this->sink->arm($this)) {
            return;
        }

        $this->logger = new NullSemanticLogger();
        $this->sink = null;
    }

    /**
     * {@inheritDoc}
     *
     * Lets callers distinguish an application-initiated (manual) operation from one
     * nested inside a framework scope (a request GET or a write command).
     */
    #[Override]
    public function isTopLevel(): bool
    {
        return $this->depth === 0;
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $id = $this->logger->open($context);
        $this->depth++;

        return $id;
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $this->logger->event($context);
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        $this->logger->close($context, $openId);
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        $log = $this->logger->flush($links);
        $this->depth = 0;

        return $log;
    }

    /**
     * Carry the sink, never the session
     *
     * The live log stops at this boundary; the flush destination does not, because the
     * unserialized logger has to arm the next request without reaching the injector.
     *
     * @return array{sink: LogSinkInterface|null}
     */
    public function __serialize(): array
    {
        return ['sink' => $this->sink];
    }

    /** @param array{sink?: mixed} $data */
    public function __unserialize(array $data): void
    {
        $sink = $data['sink'] ?? null;
        assert($sink === null || $sink instanceof LogSinkInterface);
        $this->logger = new SemanticLogger();
        $this->depth = 0;
        $this->sink = $sink;
        $this->armOrFallSilent();
    }
}
