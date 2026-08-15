<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnsupportedLogStream;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CommandContext;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\KeepMutationsAndFailures;
use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogStreamWriter;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\PolicyLogWriter;
use BEAR\QueryRepository\Log\PsrLogWriter;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Ray\Di\Injector;

use function bin2hex;
use function explode;
use function file_get_contents;
use function fileperms;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function rsort;
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
        $this->dir = sys_get_temp_dir() . '/qr-writer-test-' . bin2hex(random_bytes(6));
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

    public function testTheFileWriterKeepsTheNewestSessionsAndDropsTheRest(): void
    {
        $writer = new LogFileWriter($this->dir, 1);

        $writer->write($this->commandSession());
        $writer->write($this->commandSession());

        $sessions = glob($this->dir . '/20*.json');
        $this->assertIsArray($sessions);
        $this->assertCount(1, $sessions, 'the oldest session is dropped');
        // Names must order chronologically inside a second, or pruning keeps the wrong one
        $all = glob($this->dir . '/*.json');
        $this->assertIsArray($all);
        rsort($all);
        $this->assertSame($sessions[0], $all[1], 'the survivor is the newest session, not the oldest');
        $this->assertFileExists($this->dir . '/' . LogFileWriter::LATEST, 'latest.json is not a session file and survives');
    }

    public function testTheFileWriterRestrictsWhatItCreates(): void
    {
        // A session carries request URIs with their query strings, client validators and
        // exception text; on a shared host 0644 hands that to every local user
        (new LogFileWriter($this->dir))->write($this->commandSession());

        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertSame(0600, fileperms($this->dir . '/' . LogFileWriter::LATEST) & 0777);
    }

    public function testAStreamTargetThatIsNeitherAPathNorAStandardStreamIsRejected(): void
    {
        // `php://filter/write=…/resource=…` would truncate an unrelated file, and `ftp://user:pass@host`
        // would send every session over the network with credentials in a module argument
        $this->expectException(UnsupportedLogStream::class);

        new LogStreamWriter('php://filter/write=convert.base64-encode/resource=' . $this->file);
    }

    public function testThePsrAdapterPassesTheTreeAsContextNotAsAMessage(): void
    {
        // A tree flattened into the message would depend on the host's formatter to stay readable
        $psr = new FakePsrLogger();
        (new PsrLogWriter($psr))->write($this->commandSession());

        $this->assertCount(1, $psr->records);
        $this->assertSame(LogLevel::INFO, $psr->records[0]['level']);
        $this->assertSame('query_repository_log', $psr->records[0]['message'], 'the message is a stable key, not the payload');
        $tree = $psr->records[0]['context']['log'] ?? null;
        $this->assertIsArray($tree);
        $this->assertArrayHasKey('open', $tree, 'the whole tree survives as structured context');
    }

    public function testThePsrAdapterIgnoresAnEmptySession(): void
    {
        $psr = new FakePsrLogger();
        (new PsrLogWriter($psr))->write(new LogJson('', [], []));

        $this->assertSame([], $psr->records);
    }

    public function testThePsrAdapterLogsAtTheLevelTheHostChose(): void
    {
        // Severity is the host's call: the decision to keep this session was already taken on content
        $psr = new FakePsrLogger();
        (new PsrLogWriter($psr, LogLevel::WARNING))->write($this->commandSession());

        $this->assertCount(1, $psr->records);
        $this->assertSame(LogLevel::WARNING, $psr->records[0]['level']);
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
