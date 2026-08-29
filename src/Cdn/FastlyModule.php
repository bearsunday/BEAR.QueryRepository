<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\FastlyModule\FastlyPurgeModule;
use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use BEAR\QueryRepository\PurgerInterface;
use LogicException;
use Override;
use Ray\Di\AbstractModule;

use function class_exists;

final class FastlyModule extends AbstractModule
{
    public function __construct(
        private readonly string $fastlyApiKey,
        private readonly string $fastlyServiceId,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);

        if (! class_exists(FastlyPurgeModule::class)) {
            // Ignored because this package requires bear/fastly-module in development: with it installed the
            // branch cannot run, and removing it from require-dev to reach the branch would stop
            // the rest of this module being tested at all.
            throw new LogicException('Install bear/fastly-module'); // @codeCoverageIgnore
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->install(new FastlyPurgeModule($this->fastlyApiKey, $this->fastlyServiceId));
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(FastlyCacheControlHeaderSetter::class);
        $this->bind(PurgerInterface::class)->to(FastlyCachePurger::class);
    }
}
