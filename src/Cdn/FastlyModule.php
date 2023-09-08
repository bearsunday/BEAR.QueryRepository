<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\FastlyModule\FastlyPurgeModule;
use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use BEAR\QueryRepository\PurgerInterface;
use Ray\Di\AbstractModule;

final class FastlyModule extends AbstractModule
{
    public function __construct(
        private string $fastlyApiKey,
        private string $fastlyServiceId,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->install(new FastlyPurgeModule($this->fastlyApiKey, $this->fastlyServiceId));
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(FastlyCacheControlHeaderSetter::class);
        $this->bind(PurgerInterface::class)->to(FastlyCachePurger::class);
    }
}
