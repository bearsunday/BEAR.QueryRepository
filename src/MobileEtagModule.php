<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Detection\MobileDetect;
use LogicException;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

use function class_exists;

/**
 * Provides EtagSetterInterface
 *
 * The following bindings are provided:
 *
 * EtagSetterInterface
 */
final class MobileEtagModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        if (! class_exists(MobileDetect::class)) {
            // Ignored because this package requires mobile-detect/mobiledetectlib in development: with it installed the
            // branch cannot run, and removing it from require-dev to reach the branch would stop
            // the rest of this module being tested at all.
            throw new LogicException('Install mobile-detect/mobiledetectlib'); // @codeCoverageIgnore
        }

        $this->bind(EtagSetterInterface::class)->to(MobileEtagSetter::class)->in(Scope::SINGLETON);
    }
}
