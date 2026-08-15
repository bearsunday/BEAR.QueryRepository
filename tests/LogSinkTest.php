<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\ConcurrentRuntimeInterface;
use BEAR\QueryRepository\Log\HostRuntime;
use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\QueryRepository\Log\ShutdownFlush;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function array_merge;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function getmypid;
use function glob;
use function implode;
use function is_dir;
use function json_decode;
use function putenv;
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

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/qr-sink-test-' . getmypid();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
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

    public function testAConcurrentRuntimeIsRefusedInsteadOfLyingAboutItsSessions(): void
    {
        $sink = new ShutdownFlush(new LogFileWriter($this->logDir), new class implements ConcurrentRuntimeInterface {
            public function isConcurrent(): bool
            {
                return true;
            }
        });

        $warning = '';
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $sink->arm(new SafeSemanticLogger(new SemanticLogger()));
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('concurrent runtime', $warning);
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

    public function testTheModuleBindsTheSinkAndTheRecordingLogger(): void
    {
        $module = new DevQueryRepositoryLogModule($this->logDir, module: new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')));
        $injector = new Injector($module, __DIR__ . '/tmp');

        $this->assertInstanceOf(SafeSemanticLogger::class, $injector->getInstance(SemanticLoggerInterface::class));
        $this->assertInstanceOf(ShutdownFlush::class, $injector->getInstance(LogSinkInterface::class));
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
