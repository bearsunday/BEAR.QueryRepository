<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CommandContext;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\KeepMutationsAndFailures;
use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogStreamWriter;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\PolicyLogWriter;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function explode;
use function file_get_contents;
use function getmypid;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function rmdir;
use function substr_count;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const PHP_EOL;

/** Where a kept session ends up, and what never reaches the destination */
class LogWriterTest extends TestCase
{
    private string $dir = '';
    private string $file = '';
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/qr-writer-test-' . getmypid();
        $this->file = $this->dir . '/log.jsonl';
        $this->logger = new SafeSemanticLogger(new SemanticLogger());
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    public function testTheStreamWriterAppendsOneLinePerSession(): void
    {
        mkdir($this->dir, 0755, true);
        $writer = new LogStreamWriter($this->file);

        $writer->write($this->readSession());
        $writer->write($this->readSession());
        $lines = (string) file_get_contents($this->file);
        $this->assertSame(2, substr_count($lines, PHP_EOL), 'a session is one line, appended');
        foreach (explode(PHP_EOL, trim($lines, PHP_EOL)) as $line) {
            $this->assertIsArray(json_decode($line, true), 'every line is a complete JSON document');
        }
    }

    public function testTheStreamWriterIgnoresAnEmptySession(): void
    {
        mkdir($this->dir, 0755, true);
        (new LogStreamWriter($this->file))->write(new LogJson('', [], []));

        $this->assertFileDoesNotExist($this->file);
    }

    public function testThePolicyDecidesWhatReachesTheWriter(): void
    {
        $writer = new PolicyLogWriter(new KeepMutationsAndFailures(), new LogFileWriter($this->dir));

        $writer->write($this->readSession());
        $this->assertFalse(is_dir($this->dir), 'a healthy read never reaches the destination');

        $writer->write($this->commandSession());
        $this->assertFileExists($this->dir . '/' . LogFileWriter::LATEST, 'a mutation does');
    }

    public function testTheFileWriterKeepsOnlyTheRequestedNumberOfSessions(): void
    {
        $writer = new LogFileWriter($this->dir, 1);

        $writer->write($this->commandSession());
        $writer->write($this->commandSession());

        $sessions = glob($this->dir . '/20*.json');
        $this->assertIsArray($sessions);
        $this->assertCount(1, $sessions, 'the oldest session is dropped');
        $this->assertFileExists($this->dir . '/' . LogFileWriter::LATEST, 'latest.json is not a session file and survives');
    }

    public function testTheProductionModuleWritesThroughItsPolicy(): void
    {
        $module = new ProdQueryRepositoryLogModule($this->file, module: new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')));
        $injector = new Injector($module, __DIR__ . '/tmp');

        $this->assertInstanceOf(PolicyLogWriter::class, $injector->getInstance(LogWriterInterface::class));
        $this->assertInstanceOf(SafeSemanticLogger::class, $injector->getInstance(SemanticLoggerInterface::class));
    }

    private function readSession(): LogJson
    {
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->close(new CacheHitContext('view'), $open);

        return $this->logger->flush();
    }

    private function commandSession(): LogJson
    {
        $open = $this->logger->open(new CommandContext('onPut', [], 'CommandInterceptor'));
        $this->logger->close(new CommandResultContext(200), $open);

        return $this->logger->flush();
    }

    private function clean(): void
    {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        if (! is_dir($this->dir)) {
            return;
        }

        rmdir($this->dir);
    }
}
