<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\QueryRepository\Log\ShutdownFlush;
use FilesystemIterator;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Compiler\CompiledInjector;
use Ray\Compiler\Compiler;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\MultiBinder;
use Ray\Di\Scope;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_column;
use function assert;
use function bin2hex;
use function file_exists;
use function file_get_contents;
use function ini_set;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function serialize;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;

/** One flush, every receiver: the bound writer gets the session first, then each writer an app adds */
final class LogReceiversTest extends TestCase
{
    private string $logDir = '';
    private string $errorLog = '';
    private string $stream = '';

    #[Override]
    protected function setUp(): void
    {
        FakeSessionRecord::$received = [];
        $this->logDir = sys_get_temp_dir() . '/qr-receivers-test-' . bin2hex(random_bytes(6));
        $this->errorLog = $this->logDir . '.err';
        $this->stream = $this->logDir . '.jsonl';
    }

    #[Override]
    protected function tearDown(): void
    {
        self::remove($this->logDir);
        self::remove($this->logDir . '-di');
        foreach ([$this->errorLog, $this->stream] as $file) {
            if (! file_exists($file)) {
                continue;
            }

            unlink($file);
        }
    }

    public function testEveryWriterGetsTheSameSessionTheBoundOneFirst(): void
    {
        $this->sink(FakeFirstReceiver::class, FakeSecondReceiver::class)->flush($this->session());

        $this->assertSame(
            [FakePrimaryWriter::class, FakeFirstReceiver::class, FakeSecondReceiver::class],
            array_column(FakeSessionRecord::$received, 0),
        );
        $sessions = array_column(FakeSessionRecord::$received, 1);
        $this->assertSame($sessions[0], $sessions[1], 'one flush: a receiver gets the session the bound writer got');
        $this->assertSame($sessions[0], $sessions[2]);
    }

    public function testAWriterThatThrowsIsReportedAndTheNextStillGetsTheSession(): void
    {
        $sink = $this->sink(FakeThrowingReceiver::class, FakeSecondReceiver::class);

        $diagnostic = $this->diagnosticsOf(fn () => $sink->flush($this->session()));

        $this->assertSame([FakePrimaryWriter::class, FakeSecondReceiver::class], array_column(FakeSessionRecord::$received, 0));
        $this->assertStringContainsString(FakeThrowingReceiver::class, $diagnostic);
        $this->assertStringContainsString('the event store is down', $diagnostic);
    }

    public function testALoggerThatCannotFlushIsReportedAndNoWriterIsCalled(): void
    {
        $sink = $this->sink(FakeFirstReceiver::class);

        $diagnostic = $this->diagnosticsOf(static fn () => $sink->flush(new FakeUnflushableLogger()));

        $this->assertSame([], FakeSessionRecord::$received);
        $this->assertStringContainsString('the session cannot be flushed', $diagnostic);
    }

    public function testReceiversCrossTheSerializationBoundary(): void
    {
        $restored = unserialize(serialize($this->sink(FakeFirstReceiver::class)));
        $this->assertInstanceOf(ShutdownFlush::class, $restored);

        $restored->flush($this->session());

        $this->assertSame([FakePrimaryWriter::class, FakeFirstReceiver::class], array_column(FakeSessionRecord::$received, 0));
    }

    public function testAnAppAddsAReceiverBesideTheDevLogFile(): void
    {
        $module = $this->withReceiver(new DevQueryRepositoryLogModule($this->logDir, module: self::app()));
        $sink = (new Injector($module, __DIR__ . '/tmp'))->getInstance(LogSinkInterface::class);
        $this->assertInstanceOf(ShutdownFlush::class, $sink);

        $sink->flush($this->session());

        $this->assertFileExists($this->logDir . '/' . LogFileWriter::LATEST, 'adding a receiver replaces no binding of the log module');
        $this->assertSame([FakeFirstReceiver::class], array_column(FakeSessionRecord::$received, 0));
    }

    public function testAnAddedWriterGetsTheSessionsTheProductionPolicyDrops(): void
    {
        $module = $this->withReceiver(new ProdQueryRepositoryLogModule($this->stream, module: self::app()));
        $sink = (new Injector($module, __DIR__ . '/tmp'))->getInstance(LogSinkInterface::class);
        $this->assertInstanceOf(ShutdownFlush::class, $sink);

        $sink->flush($this->session());

        $this->assertFileDoesNotExist($this->stream, 'the retention policy drops a healthy read');
        $this->assertSame([FakeFirstReceiver::class], array_column(FakeSessionRecord::$received, 0), 'the policy applies to the bound writer only');
    }

    public function testACompiledInjectorDeliversToABoundReceiver(): void
    {
        $scriptDir = $this->logDir . '-di';
        mkdir($scriptDir, 0700, true);
        (new Compiler())->compile($this->receiversModule(FakeFirstReceiver::class), $scriptDir);
        $sink = (new CompiledInjector($scriptDir))->getInstance(ShutdownFlush::class);
        $this->assertInstanceOf(ShutdownFlush::class, $sink);

        $sink->flush($this->session());

        $this->assertSame([FakePrimaryWriter::class, FakeFirstReceiver::class], array_column(FakeSessionRecord::$received, 0));
    }

    /** @return array<string, array{AbstractModule}> */
    public static function logModulesAlone(): array
    {
        return [
            'dev' => [new DevQueryRepositoryLogModule(sys_get_temp_dir() . '/qr-receivers-unused')],
            'prod' => [new ProdQueryRepositoryLogModule(sys_get_temp_dir() . '/qr-receivers-unused.jsonl')],
        ];
    }

    #[DataProvider('logModulesAlone')]
    public function testALogModuleWorksWithoutQueryRepositoryModule(AbstractModule $logModule): void
    {
        $sink = (new Injector($logModule, __DIR__ . '/tmp'))->getInstance(LogSinkInterface::class);

        $this->assertInstanceOf(ShutdownFlush::class, $sink);
    }

    /** @param class-string<LogWriterInterface> ...$receivers */
    private function sink(string ...$receivers): ShutdownFlush
    {
        $sink = (new Injector($this->receiversModule(...$receivers), __DIR__ . '/tmp'))->getInstance(ShutdownFlush::class);
        $this->assertInstanceOf(ShutdownFlush::class, $sink);

        return $sink;
    }

    /** @param class-string<LogWriterInterface> ...$receivers */
    private function receiversModule(string ...$receivers): AbstractModule
    {
        return new class ($receivers) extends AbstractModule {
            /** @param array<class-string<LogWriterInterface>> $receivers */
            public function __construct(private readonly array $receivers)
            {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(LogWriterInterface::class)->to(FakePrimaryWriter::class);
                $this->bind(ShutdownFlush::class)->in(Scope::SINGLETON);
                $binder = MultiBinder::newInstance($this, LogWriterInterface::class);
                foreach ($this->receivers as $receiver) {
                    $binder->addBinding()->to($receiver);
                    // A compiled injector builds only bound classes (ray-di/Ray.Compiler#148)
                    $this->bind($receiver);
                }
            }
        };
    }

    private function withReceiver(AbstractModule $logModule): AbstractModule
    {
        return new class ($logModule) extends AbstractModule {
            public function __construct(private readonly AbstractModule $logModule)
            {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->install($this->logModule);
                MultiBinder::newInstance($this, LogWriterInterface::class)->addBinding()->to(FakeFirstReceiver::class);
            }
        };
    }

    private static function app(): AbstractModule
    {
        return new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
    }

    /** A healthy read: the production retention policy drops it */
    private function session(): SafeSemanticLogger
    {
        $logger = new SafeSemanticLogger();
        $logger->close(new CacheHitContext('view'), $logger->open(new GetContext('page://self/receivers')));

        return $logger;
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

    private static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($dir);
    }
}
