<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use Ray\PsrCacheModule\Psr6ArrayModule;

final class ModuleFactory
{
    public static function getInstance(string $namespace): QueryRepositoryModule
    {
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new Psr6ArrayModule());

        return $module;
    }
}
