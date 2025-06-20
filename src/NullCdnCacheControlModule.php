<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;

final class NullCdnCacheControlModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(NullCacheControlHeaderSetter::class);
    }
}
