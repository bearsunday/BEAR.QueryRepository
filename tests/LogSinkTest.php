<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\ConcurrentRuntimeInterface;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\HostRuntime;
use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\QueryRepository\Log\ShutdownFlush;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use RuntimeException;

use function array_map;
use function array_merge;
use function bin2hex;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_get_contents;
use function glob;
use function implode;
use function ini_set;
use function is_dir;
use function json_decode;
use function putenv;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function serialize;
use function set_error_handler;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;

use const PHP_BINARY;

/**
 * The flush boundary: a request that never calls flush() must still leave its log
 *
 * Recording is worth nothing if the session dies with the request, and a transfer-time hook
 * cannot cover the ways a request really ends. These tests drive the two that matter through a
 * real process: a request that returns, and a request that exits.
 */
class LogSinkTest extends TestCase
{
    private string $logDir = '';
    private string $errorLog = '';

    protected function setUp(): void
    {
        // A random suffix, not the pid: a predictable path in a world-writable directory lets a
        // local user pre-create it and read or redirect what the writer puts there
        $this->logDir = sys_get_temp_dir() . '/qr-sink-test-' . bin2hex(random_bytes(6));
        $this->errorLog = $this->logDir . '.err';
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    /**
     * Run with diagnostics captured, and return what the sink reported
     *
     * The sink reports through error_log() rather than trigger_error(), so this is the channel a
     * test has to read - and reading it keeps the message itself under test.
     */
    private function diagnosticsOf(callable $run): string
    {
        $previous = ini_set('error_log', $this->errorLog);
        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        return file_exists($this->errorLog) ? (string) file_get_contents($this->errorLog) : '';
    }

    public function testARequestThatNeverFlushesStillWritesItsLog(): void
    {
        $this->runRequest();

        $latest = $this->logDir . '/' . LogFileWriter::LATEST;
        $log = json_decode((string) file_get_contents($latest), true);
        $this->assertIsArray($log);
        $this->assertArrayHasKey('open', $log);
        $this->assertNotSame([], $log['open'], 'the GET scope reached the writer without an explicit flush');
    }

    public function testAnEarlyExitStillWritesItsLog(): void
    {
        // The 304 answer: the bootstrap stops before any transfer of the resource
        $this->runRequest('--exit');

        $this->assertFileExists($this->logDir . '/' . LogFileWriter::LATEST);
    }

    public function testTheSessionIsWrittenWholeAndOnlyOnce(): void
    {
        $this->runRequest();

        $sessions = glob($this->logDir . '/20*.json');
        $this->assertIsArray($sessions);
        $this->assertCount(1, $sessions, 'one request leaves one timestamped session');
    }

    public function testAnEmptySessionIsNotWritten(): void
    {
        // Nothing was recorded: a file would claim the request had no cache activity to show
        (new ShutdownFlush(new LogFileWriter($this->logDir)))->flush(new SafeSemanticLogger(new SemanticLogger()));

        $this->assertFalse(is_dir($this->logDir));
    }

    public function testArmingTwiceInAProcessRegistersOnce(): void
    {
        // register_shutdown_function() cannot be undone, so a second call has to be a no-op:
        // the concurrency check is the observable proof it did not run again
        $runtime = new class implements ConcurrentRuntimeInterface {
            public int $asked = 0;

            public function isConcurrent(): bool
            {
                $this->asked++;

                return false;
            }
        };
        $sink = new ShutdownFlush(new LogFileWriter($this->logDir), $runtime);
        $logger = new SafeSemanticLogger(new SemanticLogger());

        $sink->arm($logger);
        $sink->arm($logger);

        $this->assertSame(1, $runtime->asked);
    }

    public function testASecondLoggerArmingTheSameSinkIsReported(): void
    {
        // Silent total log loss is the worst failure a forensics feature can have: an armed sink
        // flushes the logger it was armed with, so an app that forgets Scope::SINGLETON on its own
        // SemanticLoggerInterface binding would lose every session after the first with no signal.
        $sink = new ShutdownFlush(new LogFileWriter($this->logDir));
        $diagnostic = $this->diagnosticsOf(static function () use ($sink): void {
            $sink->arm(new SafeSemanticLogger(new SemanticLogger()));
            $sink->arm(new SafeSemanticLogger(new SemanticLogger()));
        });

        $this->assertStringContainsString('Scope::SINGLETON', $diagnostic);
    }

    public function testARoadRunnerWorkerCountsAsConcurrentAndAPlainProcessDoesNot(): void
    {
        $runtime = new HostRuntime();
        $this->assertFalse($runtime->isConcurrent(), 'this test process serves one request');

        putenv('RR_MODE=http');
        try {
            $this->assertTrue($runtime->isConcurrent());
        } finally {
            putenv('RR_MODE');
        }
    }

    public function testAConcurrentRuntimeIsRefusedAndRecordingStopsWithIt(): void
    {
        // Refusing to arm leaves nothing that would ever drain the session, so recording has to
        // stop with it - otherwise the worker accumulates a log no one reads.
        $logger = null;
        $diagnostic = $this->diagnosticsOf(function () use (&$logger): void {
            $logger = new SafeSemanticLogger(new SemanticLogger(), new ShutdownFlush(
                new LogFileWriter($this->logDir),
                new class implements ConcurrentRuntimeInterface {
                    public function isConcurrent(): bool
                    {
                        return true;
                    }
                },
            ));
            $logger->close(new CacheHitContext('view'), $logger->open(new GetContext('page://self/html/blog-posting')));
        });

        $this->assertInstanceOf(SafeSemanticLogger::class, $logger);
        $this->assertSame([], $logger->flush()->open, 'a refused sink means nothing is recorded at all');
        $this->assertStringContainsString('concurrent runtime', $diagnostic);
        $this->assertStringContainsString('Recording is off here', $diagnostic, 'the report says what it did, not only what it refused');
    }

    public function testTheRefusalIsReportedThroughAChannelAStrictHostCannotPromote(): void
    {
        // Arming happens while the injector builds the logger. A host with a warning-to-exception
        // handler - more common on RoadRunner and Swoole, not less - would turn a trigger_error()
        // here into a boot failure on exactly the runtimes this declines to serve.
        $logger = null;
        $this->diagnosticsOf(function () use (&$logger): void {
            set_error_handler(static function (int $no, string $message): bool {
                throw new RuntimeException($message);
            });

            try {
                $logger = $this->concurrentLogger();
            } finally {
                restore_error_handler();
            }
        });

        $this->assertInstanceOf(SafeSemanticLogger::class, $logger);
        $this->assertSame([], $logger->flush()->open);
    }

    /** A logger whose sink refuses this host */
    private function concurrentLogger(): SafeSemanticLogger
    {
        return new SafeSemanticLogger(new SemanticLogger(), new ShutdownFlush(
            new LogFileWriter($this->logDir),
            new class implements ConcurrentRuntimeInterface {
                public function isConcurrent(): bool
                {
                    return true;
                }
            },
        ));
    }

    public function testAWriterThatThrowsDoesNotChangeHowTheRequestEnded(): void
    {
        // Proven where it matters: the fixture script arms a throwing writer and returns
        // normally. Thrown from a shutdown callback this used to surface as an uncaught fatal
        // with exit code 255, after the response had already been written.
        $this->runRequest('--throw');

        $this->assertFileDoesNotExist($this->logDir . '/' . LogFileWriter::LATEST, 'the destination failed, as the fixture intends');
    }

    public function testTheSinkSurvivesTheSerializationBoundaryAndTheSessionDoesNot(): void
    {
        $logger = new SafeSemanticLogger(new SemanticLogger(), new ShutdownFlush(new LogFileWriter($this->logDir)));
        $logger->open(new Log\Context\GetContext('page://self/unclosed'));

        $restored = unserialize(serialize($logger));
        $this->assertInstanceOf(SafeSemanticLogger::class, $restored);
        $this->assertSame([], $restored->flush()->open, 'the live session does not cross serialization');

        // The sink came across, so the restored logger can still write: it flushes through it
        $restored->open(new Log\Context\GetContext('page://self/after-unserialize'));
        (new ShutdownFlush(new LogFileWriter($this->logDir)))->flush($restored);
        $this->assertFileExists($this->logDir . '/' . LogFileWriter::LATEST);
    }

    public function testTheModuleBindsOneSinkAndOneRecordingLoggerPerProcess(): void
    {
        $module = new DevQueryRepositoryLogModule($this->logDir, module: new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')));
        $injector = new Injector($module, __DIR__ . '/tmp');

        $this->assertInstanceOf(SafeSemanticLogger::class, $injector->getInstance(SemanticLoggerInterface::class));
        $this->assertInstanceOf(ShutdownFlush::class, $injector->getInstance(LogSinkInterface::class));
        // Arming is process-global state: two sinks would register two flushes, and the second
        // logger's session would be the one nothing drains
        $this->assertSame($injector->getInstance(LogSinkInterface::class), $injector->getInstance(LogSinkInterface::class));
        $this->assertSame($injector->getInstance(SemanticLoggerInterface::class), $injector->getInstance(SemanticLoggerInterface::class));
    }

    private function runRequest(string ...$args): void
    {
        $arguments = array_map('escapeshellarg', array_merge([$this->logDir], $args));
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/script/sink-request.php')
            . ' ' . implode(' ', $arguments) . ' 2>&1';
        exec($command, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    private function clean(): void
    {
        $files = glob($this->logDir . '/*.json');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        if (! is_dir($this->logDir)) {
            return;
        }

        rmdir($this->logDir);
    }
}
