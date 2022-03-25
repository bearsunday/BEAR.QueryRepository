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
    /** @var string */
    private $version;

    public function __construct(string $cacheVersion, ?AbstractModule $module = null)
    {
        $this->version = $cacheVersion;
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
