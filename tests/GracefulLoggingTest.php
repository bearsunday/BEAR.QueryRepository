<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

/**
 * Logging must never break cache behavior
 *
 * Even when the underlying semantic logger throws on every call, SafeSemanticLogger
 * swallows the failure so cache reads/writes (and the dependency cascade) keep working.
 */
class GracefulLoggingTest extends TestCase
{
    public function testCacheWorksWhenLoggerAlwaysThrows(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->toInstance(
                    new SafeSemanticLogger(new ThrowingSemanticLogger()),
                );
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);

        // Building the 3-level dependency chain must succeed despite the failing logger.
        $ro = $resource->get('page://self/dep/level-one');
        $this->assertSame(200, $ro->code);
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);

        // A second access is served from cache: same stored ETag, and still no exception leaks out.
        $cached = $resource->get('page://self/dep/level-one');
        $this->assertSame($ro->headers[Header::ETAG], $cached->headers[Header::ETAG]);
    }
}
