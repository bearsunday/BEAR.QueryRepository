<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\Di\Set;
use Ray\Di\MultiBinding\Map;
use Throwable;

use function assert;
use function error_log;
use function get_debug_type;
use function is_array;
use function register_shutdown_function;

/**
 * Flushes at process shutdown: the request's end where a process serves one request
 *
 * Shutdown runs after the response is written and after `exit()`, which is what makes it the
 * boundary LogSinkInterface describes.
 *
 * Diagnostics go to `error_log()`, never `trigger_error()`. Arming happens while the injector
 * builds the logger, and a host with a strict error handler turns a warning raised there into
 * an exception thrown out of the provider - which would take down the very hosts this class
 * declines to serve.
 */
final class ShutdownFlush implements LogSinkInterface
{
    /** Registration cannot be undone, so it happens once per process - never per session */
    private bool $armed = false;

    /** The session the registered callback will flush; a second, different logger is a mistake */
    private SemanticLoggerInterface|null $armedLogger = null;

    /** @var list<LogWriterInterface> */
    private array $receivers = [];

    /** @param Map<LogWriterInterface>|null $receivers */
    public function __construct(
        private LogWriterInterface $writer,
        private ConcurrentRuntimeInterface $runtime = new HostRuntime(),
        #[Set(LogWriterInterface::class)]
        Map|null $receivers = null,
    ) {
        // Built now so no Map, and the injector it holds, is serialized
        foreach ($receivers ?? [] as $receiver) {
            $this->receivers[] = $receiver;
        }
    }

    /**
     * Carry the configuration, never the arming
     *
     * A compiled app is serialized once; a captured `armed` would silence every request served
     * from that snapshot.
     *
     * @return array{writer: LogWriterInterface, runtime: ConcurrentRuntimeInterface, receivers: list<LogWriterInterface>}
     */
    public function __serialize(): array
    {
        return ['writer' => $this->writer, 'runtime' => $this->runtime, 'receivers' => $this->receivers];
    }

    /** @param array{writer?: mixed, runtime?: mixed, receivers?: mixed} $data */
    public function __unserialize(array $data): void
    {
        $writer = $data['writer'] ?? null;
        $runtime = $data['runtime'] ?? null;
        $receivers = $data['receivers'] ?? [];
        assert($writer instanceof LogWriterInterface);
        assert($runtime instanceof ConcurrentRuntimeInterface);
        assert(is_array($receivers));
        $this->writer = $writer;
        $this->runtime = $runtime;
        $this->receivers = [];
        foreach ($receivers as $receiver) {
            assert($receiver instanceof LogWriterInterface);
            $this->receivers[] = $receiver;
        }

        $this->armed = false;
        $this->armedLogger = null;
    }

    #[Override]
    public function arm(SemanticLoggerInterface $logger): bool
    {
        if ($this->armed) {
            if ($logger !== $this->armedLogger) {
                error_log(
                    'QueryRepository log: a second logger armed an already-armed sink, so its session '
                    . 'will never be flushed. Bind SemanticLoggerInterface annotated with #[CacheLog] '
                    . 'in Scope::SINGLETON.',
                );
            }

            return true;
        }

        $this->armed = true;
        if ($this->runtime->isConcurrent()) {
            error_log(
                'QueryRepository log: a shutdown flush is unsafe on a concurrent runtime - shutdown '
                . 'arrives once per worker, so sessions would accumulate and concurrent requests share '
                . 'one. Recording is off here; bind a request-scoped LogSinkInterface to record.',
            );

            return false;
        }

        $this->armedLogger = $logger;
        register_shutdown_function(fn () => $this->flush($logger));

        return true;
    }

    #[Override]
    public function flush(SemanticLoggerInterface $logger): void
    {
        // The log is a side channel: a failing destination must not change how the request ended or
        // keep the next one from the session. Thrown from a shutdown callback it would be a fatal.
        try {
            $log = $logger->flush();
        } catch (Throwable $e) {
            error_log('QueryRepository log: flush failed - ' . $e::class . ': ' . $e->getMessage());

            return;
        }

        foreach ([$this->writer, ...$this->receivers] as $writer) {
            try {
                $writer->write($log);
            } catch (Throwable $e) {
                error_log('QueryRepository log: flush failed - ' . get_debug_type($writer) . ' - ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }
}
