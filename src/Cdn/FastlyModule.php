<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use Ray\Di\AbstractModule;

final class FastlyModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(FastlyCacheControlHeaderSetter::class);
    }
}
