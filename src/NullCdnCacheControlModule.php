<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;

final class NullCdnCacheControlModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(NullCacheControlHeaderSetter::class);
    }
}
