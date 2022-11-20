<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
        private string $version,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->install(new CacheNamespaceModule($this->version));
    }
}
