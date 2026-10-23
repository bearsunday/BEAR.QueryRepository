<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;

/**
 * Provides UnstorableGateInterface and derived bindings
 *
 * The following binding is provided:
 *
 * UnstorableGateInterface
 *
 * Install alongside DevEtagModule to turn a response marked unstorable (issue #222) into a
 * thrown UnstorableResponseDetected instead of the package default of a silent skip.
 */
final class DevUnstorableGateModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(UnstorableGateInterface::class)->to(ThrowingUnstorableGate::class);
    }
}
