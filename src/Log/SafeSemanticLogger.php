<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;

/**
 * Facade over the total (never-throwing) SemanticLogger, resolved per request through a store
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
 *  - Session resolution: the facade is bound once per process, the depth count and delegate
 *    logger are per request, so every call resolves them through SessionStoreInterface.
 *  - Serialization boundary: a compiled app serializes the injector between requests; session
 *    state never crosses that boundary. It is also the only hook that runs on every request, so
 *    the flush sink (when one is bound) is armed from there.
 */
final class SafeSemanticLogger implements SemanticLoggerInterface, TopLevelAwareInterface
{
    /** Once the sink refuses this host, every call is answered by silentSession, never the store */
    private bool $silent = false;
    private Session $silentSession;

    public function __construct(
        private LogSinkInterface|null $sink = null,
        private SessionStoreInterface $store = new ProcessSession(),
    ) {
        $this->silentSession = new Session(new NullSemanticLogger());
        $this->armOrFallSilent();
    }

    /**
     * Record only while something will drain the session
     *
     * A sink that refuses this host (a concurrent runtime, where shutdown arrives once per
     * worker) leaves no drain at all, and an undrained session grows for the life of the
     * process. Recording into it would trade a log nobody reads for memory, so every session
     * from here on is the silent one instead. No sink at all is a different case: the caller
     * flushes it (tests, demos, a host with its own lifecycle), so recording stays on.
     */
    private function armOrFallSilent(): void
    {
        if ($this->sink === null || $this->sink->arm($this)) {
            return;
        }

        $this->silent = true;
        $this->sink = null;
    }

    /** The session of the request in progress, or the silent one once recording has stopped */
    private function current(): Session
    {
        return $this->silent ? $this->silentSession : $this->store->current();
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
        return $this->current()->depth === 0;
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $session = $this->current();
        $id = $session->logger->open($context);
        $session->depth++;

        return $id;
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $this->current()->logger->event($context);
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        $session = $this->current();
        $session->logger->close($context, $openId);
        if ($session->depth > 0) {
            $session->depth--;
        }
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        $session = $this->current();
        $log = $session->logger->flush($links);
        $session->depth = 0;
        $this->store->forget();

        return $log;
    }

    /**
     * Carry the sink and the store, never a live session
     *
     * The unserialized logger has to arm the next request without reaching the injector.
     *
     * @return array{sink: LogSinkInterface|null, store: SessionStoreInterface}
     */
    public function __serialize(): array
    {
        return ['sink' => $this->sink, 'store' => $this->store];
    }

    /** @param array{sink?: mixed, store?: mixed} $data */
    public function __unserialize(array $data): void
    {
        $sink = $data['sink'] ?? null;
        assert($sink === null || $sink instanceof LogSinkInterface);
        $store = $data['store'] ?? null;
        assert($store instanceof SessionStoreInterface);

        $this->silent = false;
        $this->silentSession = new Session(new NullSemanticLogger());
        $this->sink = $sink;
        $this->store = $store;
        $this->armOrFallSilent();
    }
}
