<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;

/**
 * Provides EtagSetterInterface and derived bindings
 *
 * The following module is installed:
 *
 * EtagSetterInterface
 */
final class DevEtagModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(EtagSetterInterface::class)->to(DevEtagSetter::class);
    }
}
