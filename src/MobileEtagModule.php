<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class MobileEtagModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(EtagSetterInterface::class)->to(MobileEtagSetter::class)->in(Scope::SINGLETON);
    }
}
