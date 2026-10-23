<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class DevUnstorableGateModuleTest extends TestCase
{
    public function testOverrideIsTheSupportedWiringAndResolvesToThrowingUnstorableGate(): void
    {
        // The only wiring that actually works: QueryRepositoryModule already binds
        // UnstorableGateInterface (issue #222), and Ray.Di's install() never replaces an
        // existing binding (Container::merge() keeps the existing key), so this module
        // must be installed via override().
        $module = new QueryRepositoryModule();
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $gate = $injector->getInstance(UnstorableGateInterface::class);

        $this->assertInstanceOf(ThrowingUnstorableGate::class, $gate);
    }

    public function testTheResolvedGateActuallyThrowsWhenMarkedUnstorable(): void
    {
        $module = new QueryRepositoryModule();
        $module->override(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $gate = $injector->getInstance(UnstorableGateInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $mark->mark('csrf-token-issued');

        $this->expectException(UnstorableResponseDetected::class);
        $gate->reason(new None());
    }

    public function testPlainInstallDoesNotSwapTheExistingBinding(): void
    {
        // The negative case this module's docblock warns against: install() silently
        // keeps QueryRepositoryModule's own (non-throwing) binding.
        $module = new QueryRepositoryModule();
        $module->install(new DevUnstorableGateModule());
        $injector = new Injector($module, __DIR__ . '/tmp');

        $gate = $injector->getInstance(UnstorableGateInterface::class);

        $this->assertNotInstanceOf(ThrowingUnstorableGate::class, $gate, 'install() must not be documented as working - it silently keeps the original binding');
    }
}
