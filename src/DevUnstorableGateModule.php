<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;

/**
 * Provides a throwing UnstorableGateInterface; install via override(), not install()
 *
 * QueryRepositoryModule already binds UnstorableGateInterface (#222), and Ray.Di's
 * install() never replaces an existing binding, so only override() takes effect. The
 * concrete UnstorableGate is bound too, since ThrowingUnstorableGate decorates it, not
 * the interface.
 */
final class DevUnstorableGateModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(UnstorableGate::class);
        $this->bind(UnstorableGateInterface::class)->to(ThrowingUnstorableGate::class);
    }
}
