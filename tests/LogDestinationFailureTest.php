<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogStreamWriter;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\QueryRepository\Log\ShutdownFlush;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function glob;
use function ini_set;
use function is_dir;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A destination that cannot be written must not change how the request ended
 *
 * These paths run inside `register_shutdown_function`, after the response is written. An
 * exception there is an uncaught fatal and a PHP warning there is invisible, so the writers
 * report to `error_log()` and give up. A forensic log whose absence is silent would be worse
 * than none, which is why each case asserts the report as well as the survival.
 */
class LogDestinationFailureTest extends TestCase
{
    private string $dir = '';
    private string $errorLog = '';
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/qr-failure-test-' . bin2hex(random_bytes(6));
        $this->errorLog = $this->dir . '.err';
        $this->logger = new SafeSemanticLogger(new SemanticLogger());
        $this->logger->close(new CacheHitContext('view'), $this->logger->open(new GetContext('page://self/html/blog-posting')));
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        if (! file_exists($this->errorLog)) {
            return;
        }

        unlink($this->errorLog);
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        chmod($path, 0700);
        foreach (glob($path . '/*') ?: [] as $child) {
            $this->remove($child);
        }

        rmdir($path);
    }

    public function testAFlushWhoseDestinationThrowsIsReportedAndSwallowed(): void
    {
        $sink = new ShutdownFlush(new class implements LogWriterInterface {
            #[Override]
            public function write(LogJson $log): void
            {
                throw new RuntimeException('the log volume is not mounted');
            }
        });

        $diagnostic = $this->diagnosticsOf(fn () => $sink->flush($this->logger));

        $this->assertStringContainsString('flush failed', $diagnostic);
        $this->assertStringContainsString('the log volume is not mounted', $diagnostic);
    }

    public function testADirectoryThatCannotBeCreatedIsReported(): void
    {
        // A file where the directory should be: mkdir cannot succeed and never will
        $blocked = $this->dir . '/blocked';
        mkdir($this->dir, 0700, true);
        file_put_contents($blocked, 'not a directory');
        $writer = new LogFileWriter($blocked . '/deeper');

        $diagnostic = $this->diagnosticsOf(fn () => $writer->write($this->logger->flush()));

        $this->assertStringContainsString('cannot create the log directory', $diagnostic);
    }

    public function testASessionThatCannotBeWrittenIsReported(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('chmod cannot revoke write access on Windows, so the failure cannot be staged there');
        }

        mkdir($this->dir, 0700, true);
        chmod($this->dir, 0500); // readable, not writable: the directory exists, the write cannot
        $writer = new LogFileWriter($this->dir);

        $diagnostic = $this->diagnosticsOf(fn () => $writer->write($this->logger->flush()));

        $this->assertStringContainsString('cannot write', $diagnostic);
        $this->assertFileDoesNotExist($this->dir . '/' . LogFileWriter::LATEST);
    }

    public function testALatestThatCannotBeReplacedLeavesNoLeftover(): void
    {
        // A directory where latest.json belongs: the session file is written, the rename is not
        mkdir($this->dir . '/' . LogFileWriter::LATEST, 0700, true);
        (new LogFileWriter($this->dir))->write($this->logger->flush());

        $sessions = glob($this->dir . '/20*.json');
        $this->assertIsArray($sessions);
        $this->assertCount(1, $sessions, 'the session itself was written');
        $this->assertSame([], glob($this->dir . '/*.tmp'), 'the pending copy was cleaned up');
    }

    public function testAStreamThatCannotBeOpenedIsReported(): void
    {
        // A directory cannot be opened for writing
        mkdir($this->dir, 0700, true);
        $writer = new LogStreamWriter($this->dir);

        $diagnostic = $this->diagnosticsOf(fn () => $writer->write($this->logger->flush()));

        $this->assertStringContainsString('cannot open', $diagnostic);
    }

    public function testAStreamTargetGetsItsParentDirectory(): void
    {
        $writer = new LogStreamWriter($this->dir . '/nested/log.jsonl');

        $writer->write($this->logger->flush());

        $this->assertFileExists($this->dir . '/nested/log.jsonl');
    }

    public function testAStreamTargetIsRestrictedToItsOwner(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX file modes: Windows expresses this through ACLs, which this writer does not claim to set');
        }

        (new LogStreamWriter($this->dir . '/nested/log.jsonl'))->write($this->logger->flush());

        $this->assertSame(0700, fileperms($this->dir . '/nested') & 0777);
        $this->assertSame(0600, fileperms($this->dir . '/nested/log.jsonl') & 0777);
    }

    public function testAStreamTargetWhoseDirectoryCannotBeCreatedIsReported(): void
    {
        $blocked = $this->dir . '/blocked';
        mkdir($this->dir, 0700, true);
        file_put_contents($blocked, 'not a directory');
        $writer = new LogStreamWriter($blocked . '/deeper/log.jsonl');

        $diagnostic = $this->diagnosticsOf(fn () => $writer->write($this->logger->flush()));

        $this->assertStringContainsString('cannot create the log directory', $diagnostic);
    }

    public function testAStandardOutputStreamIsWrittenWithoutTouchingTheFilesystem(): void
    {
        // The documented production default: no directory is created and no mode is set, because
        // the host owns the stream
        ob_start();
        (new LogStreamWriter('php://output'))->write($this->logger->flush());
        $written = (string) ob_get_clean();

        $this->assertStringContainsString('"type":"get"', $written);
        $this->assertFalse(is_dir($this->dir), 'a stream target creates nothing on disk');
    }

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
}
