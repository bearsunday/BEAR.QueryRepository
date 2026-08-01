<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use BEAR\QueryRepository\Log\Context\LogSessionBrokenContext;
use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Throwable;

/**
 * Best-effort decorator that guarantees logging never breaks cache behavior
 *
 * Cache observability is a side-channel: an exception from the logger (e.g. a
 * LIFO open/close mismatch, or flush on an unclosed session) must never escape
 * into the cache read/write path. Every delegated call is guarded; on the first
 * failure the session is marked "broken" and all further calls become no-ops
 * until flush() starts a fresh session.
 *
 * Recovery: if the delegate ends up in a broken state (e.g. an unclosed session
 * left its internal stack dirty), flush() replaces it with a fresh SemanticLogger
 * so the *next* session logs normally — a single failure never permanently
 * silences logging. The wiped session does not vanish silently: the recovery
 * flush returns a `log_session_broken` sentinel scope (carrying the throwable's
 * message) instead of an empty log, so "no records" is never misread as "no
 * cache activity". The delegate is accepted as an interface so a throwing fake
 * can be injected in tests to prove graceful degradation.
 */
final class SafeSemanticLogger implements SemanticLoggerInterface
{
    private const EMPTY_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    private bool $broken = false;
    private int $depth = 0;

    public function __construct(private SemanticLoggerInterface $logger)
    {
    }

    /**
     * Whether no operation scope is currently open (the next open would be top-level)
     *
     * Lets callers distinguish an application-initiated (manual) operation from one
     * nested inside a framework scope (a request GET or a write command).
     */
    public function isTopLevel(): bool
    {
        return $this->depth === 0;
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        if ($this->broken) {
            return '';
        }

        try {
            $id = $this->logger->open($context);
            $this->depth++;

            return $id;
        } catch (Throwable) {
            $this->broken = true;

            return '';
        }
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        if ($this->broken) {
            return;
        }

        try {
            $this->logger->event($context);
        } catch (Throwable) {
            $this->broken = true;
        }
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        if ($this->broken || $openId === '') {
            return;
        }

        try {
            $this->logger->close($context, $openId);
            if ($this->depth > 0) {
                $this->depth--;
            }
        } catch (Throwable) {
            $this->broken = true;
        }
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        try {
            $log = $this->logger->flush($links);
            $this->broken = false;
            $this->depth = 0;

            return $log;
        } catch (Throwable $e) {
            // The delegate's internal state may be dirty (e.g. an unclosed session).
            // Replace it with a fresh logger so the next session recovers, and never
            // surface the failure to the cache caller.
            $this->logger = new SemanticLogger();
            $this->broken = false;
            $this->depth = 0;

            try {
                // Leave a tombstone for the wiped session: an empty flush would read
                // as "no cache activity", hiding that records were lost.
                $sentinel = new LogSessionBrokenContext($e->getMessage() !== '' ? $e->getMessage() : $e::class);
                $openId = $this->logger->open($sentinel);
                $this->logger->close($sentinel, $openId);

                return $this->logger->flush($links);
            } catch (Throwable) { // @codeCoverageIgnoreStart
                return new LogJson(self::EMPTY_SCHEMA_URL, [], [], [], $links); // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * Serialize without session state (no live log carried across serialization)
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * Session state is never carried across serialization, so the payload is ignored.
     *
     * @param array<string, mixed> $data
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function __unserialize(array $data): void
    {
        $this->logger = new SemanticLogger();
        $this->broken = false;
    }
}
