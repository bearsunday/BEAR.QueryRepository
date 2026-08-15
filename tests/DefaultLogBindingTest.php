<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
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
        $this->assertInstanceOf(NullSemanticLogger::class, $this->injector->getInstance(SemanticLoggerInterface::class));
    }

    public function testACachedRequestStillWorksAndLeavesNoSession(): void
    {
        $resource = $this->injector->getInstance(ResourceInterface::class);
        $logger = $this->injector->getInstance(SemanticLoggerInterface::class);

        $resource->get('page://self/html/blog-posting');

        $log = $logger->flush();
        $this->assertSame([], $log->open, 'nothing was opened');
        $this->assertSame([], $log->events, 'nothing was recorded');
    }
}
