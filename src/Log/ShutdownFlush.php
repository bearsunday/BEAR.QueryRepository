<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;
use function register_shutdown_function;
use function trigger_error;

use const E_USER_WARNING;

/**
 * Flushes at process shutdown: the request's end under PHP-FPM and the CLI
 *
 * Shutdown functions run after the response is written and after `exit()`, so the two paths a
 * transfer-time hook misses are covered: a 304 answer that stops in the bootstrap, and an
 * uncaught error - the case whose log is worth the most.
 */
final class ShutdownFlush implements LogSinkInterface
{
    /** Registration cannot be undone, so it happens once per process - never per session */
    private bool $armed = false;

    public function __construct(
        private LogWriterInterface $writer,
        private ConcurrentRuntimeInterface $runtime = new HostRuntime(),
    ) {
    }

    /**
     * Carry the configuration, never the arming
     *
     * A compiled app is serialized once; a captured `armed` would silence every request served
     * from that snapshot.
     *
     * @return array{writer: LogWriterInterface, runtime: ConcurrentRuntimeInterface}
     */
    public function __serialize(): array
    {
        return ['writer' => $this->writer, 'runtime' => $this->runtime];
    }

    /** @param array{writer?: mixed, runtime?: mixed} $data */
    public function __unserialize(array $data): void
    {
        $writer = $data['writer'] ?? null;
        $runtime = $data['runtime'] ?? null;
        assert($writer instanceof LogWriterInterface);
        assert($runtime instanceof ConcurrentRuntimeInterface);
        $this->writer = $writer;
        $this->runtime = $runtime;
        $this->armed = false;
    }

    #[Override]
    public function arm(SemanticLoggerInterface $logger): void
    {
        if ($this->armed) {
            return;
        }

        $this->armed = true;
        if ($this->runtime->isConcurrent()) {
            trigger_error(
                'QueryRepository log: a shutdown flush is unsafe on a concurrent runtime - shutdown '
                . 'arrives once per worker, so sessions accumulate and concurrent requests share one. '
                . 'Bind a request-scoped LogSinkInterface, or do not install the log module here.',
                E_USER_WARNING,
            );

            return;
        }

        register_shutdown_function(fn () => $this->flush($logger));
    }

    #[Override]
    public function flush(SemanticLoggerInterface $logger): void
    {
        $this->writer->write($logger->flush());
    }
}
