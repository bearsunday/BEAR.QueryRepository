<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Psr6ApcuModule;

/**
 * @deprecated
 *
 * Use \Ray\PsrCacheModule\Psr6ApcuModule
 */
class StorageApcModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->install(new Psr6ApcuModule());
    }
}
