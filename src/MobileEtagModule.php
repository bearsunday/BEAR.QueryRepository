<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use Ray\Di\Scope;

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
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(EtagSetterInterface::class)->to(MobileEtagSetter::class)->in(Scope::SINGLETON);
    }
}
