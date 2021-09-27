<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;

class FastlyModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(FastlyCacheControlHeaderSetter::class);
    }
}
