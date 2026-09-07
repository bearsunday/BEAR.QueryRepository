<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;
use function error_log;

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
 *  - Session resolution: this facade is bound once per process (see SafeSemanticLoggerProvider),
 *    but the depth count and the delegate logger it drives are per request, so every call asks
 *    the injected SessionStoreInterface for the session of the request in progress rather than
 *    holding either one itself. The default store answers with one session for the whole
 *    process, which is correct under PHP-FPM/CLI and wrong on a concurrent host - see
 *    SessionStoreInterface for what such a host binds instead.
 *  - Serialization boundary: a compiled app serializes the injector between requests; session
 *    state never crosses that boundary. It is also the only hook that runs on every request, so
 *    the flush sink (when one is bound) is armed from there.
 */
final class SafeSemanticLogger implements SemanticLoggerInterface, TopLevelAwareInterface
{
    /**
     * Set by armOrFallSilent() when the sink proves nothing will drain a session on this host.
     * The silent session lives outside the store: a store that splits sessions by request is a
     * concurrent host's, and a silenced logger must not depend on it to record nothing.
     */
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
     * The live session stops at this boundary (the store is asked again for a fresh one on the
     * next call); the flush destination does not, because the unserialized logger has to arm
     * the next request without reaching the injector.
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

        $this->silent = false;
        $this->silentSession = new Session(new NullSemanticLogger());
        $this->sink = $sink;
        if (! $store instanceof SessionStoreInterface) {
            // A snapshot without a store predates this class; guessing one could reinstate the
            // shared session a concurrent host binds a store to avoid, so record nothing instead.
            error_log('QueryRepository log: the compiled snapshot carries no session store; recording is off until the app is recompiled.');
            $this->store = new ProcessSession();
            $this->silent = true;

            return;
        }

        $this->store = $store;
        $this->armOrFallSilent();
    }
}
