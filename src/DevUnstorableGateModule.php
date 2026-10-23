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
 * Install via override(), not install(): QueryRepositoryModule already binds
 * UnstorableGateInterface (issue #222), and Ray.Di's install() never replaces a binding the
 * installer already holds (Container::merge() keeps the existing key), so a plain
 * `$module->install(new DevUnstorableGateModule())` silently keeps the original, non-throwing
 * binding. Use `$module->override(new DevUnstorableGateModule())` instead, the same way a
 * test overrides TwigModule's template path.
 *
 * ThrowingUnstorableGate decorates the concrete UnstorableGate, not the interface, so this
 * module binds the concrete class too: once UnstorableGateInterface points elsewhere,
 * UnstorableGate stops being auto-wireable via the interface binding alone.
 *
 * Use alongside DevEtagModule (installed normally - it does not conflict) to turn a response
 * marked unstorable (issue #222) into a thrown UnstorableResponseDetected instead of the
 * package default of a silent skip.
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
