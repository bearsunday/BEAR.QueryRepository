<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\CacheNamespaceModule;

/**
 * Provides CacheNamespace and derived bindings
 *
 * The following module is installed:
 *
 * -CacheNamespaceModule
 */
final class CacheVersionModule extends AbstractModule
{
    public function __construct(
        private readonly string $version,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->install(new CacheNamespaceModule($this->version));
    }
}
