<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

/**
 * Recording is off until an app asks for it
 *
 * The package default is a null cache engine; the log follows the same rule. Off means nothing
 * is recorded at all - not recorded and discarded - because a buffered session costs 1.4 KB per
 * request and hands the host a flush duty in exchange for a log nobody reads.
 */
class DefaultLogBindingTest extends TestCase
{
    private Injector $injector;

    protected function setUp(): void
    {
        // QueryRepositoryModule on its own: what an app gets without a log module
        $module = new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld')));
        $this->injector = new Injector($module, __DIR__ . '/tmp');
    }

    public function testTheDefaultLoggerRecordsNothing(): void
    {
        $this->assertInstanceOf(NullSemanticLogger::class, $this->injector->getInstance(SemanticLoggerInterface::class, CacheLog::class));
    }

    public function testTheDefaultLoggerIsShared(): void
    {
        // Without singleton scope every injection point gets its own instance, and the assertion
        // below would inspect a logger the resource never used - passing for the wrong reason
        $this->assertSame(
            $this->injector->getInstance(SemanticLoggerInterface::class, CacheLog::class),
            $this->injector->getInstance(SemanticLoggerInterface::class, CacheLog::class),
        );
    }

    public function testACachedRequestStillWorksAndLeavesNoSession(): void
    {
        $resource = $this->injector->getInstance(ResourceInterface::class);
        $logger = $this->injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        $resource->get('page://self/html/blog-posting');

        $log = $logger->flush();
        $this->assertSame([], $log->open, 'nothing was opened');
        $this->assertSame([], $log->events, 'nothing was recorded');
    }

    public function testTheAppsOwnSemanticLoggerBindingIsLeftAlone(): void
    {
        // The interface belongs to koriym/semantic-logger, not to this package: Be Framework binds
        // it for its becoming tree. Ray.Di keeps the binding that lands first, so an unqualified
        // default here would decide by install order - silently, since a null logger still works.
        $appLogger = new SemanticLogger();
        $module = new class ($appLogger, new FakeEtagPoolModule(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld')))) extends AbstractModule {
            public function __construct(private SemanticLogger $appLogger, AbstractModule $module)
            {
                parent::__construct($module);
            }

            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->toInstance($this->appLogger);
            }
        };
        $injector = new Injector($module, __DIR__ . '/tmp');

        $this->assertSame($appLogger, $injector->getInstance(SemanticLoggerInterface::class), 'the app keeps its logger');
        $this->assertInstanceOf(NullSemanticLogger::class, $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class));
    }
}
