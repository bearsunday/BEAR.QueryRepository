<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class RepositoryLoggerBindingTest extends TestCase
{
    /** @psalm-suppress DeprecatedInterface */
    public function testAnApplicationThatStillAsksForTheDeprecatedLoggerGetsOne(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'));

        $logger = $injector->getInstance(RepositoryLoggerInterface::class);

        $this->assertSame($logger, $injector->getInstance(RepositoryLoggerInterface::class));
        $this->assertSame('', (string) $logger);
    }
}
