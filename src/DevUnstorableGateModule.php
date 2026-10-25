<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Provides UnstorableWriteObserverInterface
 *
 * The following binding is provided:
 *
 * UnstorableWriteObserverInterface
 *
 * Install via override(), not install(): QueryRepositoryModule already binds
 * UnstorableWriteObserverInterface (to the package default, no-op NullUnstorableWriteObserver),
 * and Ray.Di's install() never replaces a binding the installer already holds
 * (Container::merge() keeps the existing key), so a plain
 * `$module->install(new DevUnstorableGateModule())` silently keeps the original, no-op binding.
 * Use `$module->override(new DevUnstorableGateModule())` instead, the same way a test overrides
 * TwigModule's template path.
 *
 * Turns a response marked unstorable (issue #222) that the package would otherwise silently
 * skip into a thrown `Exception\UnstorableResponseDetected` once, after rendering completes -
 * useful alongside other development-time tooling such as DevEtagModule. Unlike the old
 * throwing gate this replaces, a hole legitimately refusing its own write (a CSRF form hole on
 * an otherwise-correct `#[DonutCache]` page) is never reported: see
 * `UnstorableWriteObserverInterface`'s docblock.
 */
final class DevUnstorableGateModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(UnstorableWriteObserverInterface::class)
            ->to(ThrowingUnstorableWriteObserver::class)
            ->in(Scope::SINGLETON);
    }
}
