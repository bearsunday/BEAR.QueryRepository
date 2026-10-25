<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Counter;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function dirname;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * EmbedOnceInterceptor's own docblock argues Ray.Aop's onion ordering makes its position
 * relative to EmbedInterceptor independent of which module - `QueryRepositoryModule`
 * (binds EmbedOnceInterceptor) or `ResourceModule` (binds EmbedInterceptor) - is installed
 * first. This test is the empirical check for both orders Ray.Di actually allows:
 *
 * - "default": `new QueryRepositoryModule(new ResourceModule(...))` - every other test in
 *   this suite boots this way (see `ModuleFactory`); QueryRepositoryModule's own
 *   `configure()` pointcuts land in the container before ResourceModule's are merged in.
 * - "reversed": a wrapping module that calls `install(new ResourceModule(...))` before
 *   `install(new QueryRepositoryModule())` - the opposite merge order.
 *
 * Both must swap the embed before onGet() runs (CounterDonut's child executes exactly
 * once) and neither must trigger EmbedOnceInterceptor's own "ran too early" E_USER_WARNING.
 */
class EmbedOnceInterceptorInstallOrderTest extends TestCase
{
    private const COUNTER_DONUT = 'page://self/html/counter-donut';

    protected function setUp(): void
    {
        Counter::$calls = 0;
    }

    public function testDefaultOrderResourceModuleWrappedByQueryRepositoryModule(): void
    {
        $module = new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'));
        $this->assertOnceSemanticsHold($module);
    }

    public function testReversedOrderResourceModuleInstalledBeforeQueryRepositoryModule(): void
    {
        $module = new class extends AbstractModule {
            protected function configure(): void
            {
                $this->install(new ResourceModule('FakeVendor\HelloWorld'));
                $this->install(new QueryRepositoryModule());
            }
        };
        $this->assertOnceSemanticsHold($module);
    }

    private function assertOnceSemanticsHold(AbstractModule $module): void
    {
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->to(NullSemanticLogger::class);
            }
        });
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });
        try {
            $injector = new Injector($module, __DIR__ . '/tmp');
            $resource = $injector->getInstance(ResourceInterface::class);
            $ro = $resource->get(self::COUNTER_DONUT);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'EmbedOnceInterceptor must never warn that it ran before EmbedInterceptor, in either install order');
        $this->assertSame(1, Counter::$calls, 'the embed must still execute exactly once, in either install order');
        $view = (string) $ro->view;
        $this->assertStringContainsString('read:1', $view);
        $this->assertStringContainsString('call:1', $view);
    }
}
