<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Detection\MobileDetect;
use LogicException;
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
    protected function configure(): void
    {
        if (! class_exists(MobileDetect::class)) {
            throw new LogicException('Install mobile-detect/mobiledetectlib'); // @codeCoverageIgnore
        }

        $this->bind(EtagSetterInterface::class)->to(MobileEtagSetter::class)->in(Scope::SINGLETON);
    }
}
