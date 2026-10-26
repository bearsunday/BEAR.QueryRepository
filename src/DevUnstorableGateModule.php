<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Reports a skipped unstorable write during development.
 *
 * The following binding is provided:
 *
 * UnstorableWriteObserverInterface
 *
 * Use `override()`, not `install()`: `QueryRepositoryModule` already binds the no-op
 * observer, and `install()` keeps the existing binding.
 */
final class DevUnstorableGateModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(UnstorableWriteObserverInterface::class)->to(WarningUnstorableWriteObserver::class)->in(Scope::SINGLETON);
    }
}
