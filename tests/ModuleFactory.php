<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\Resource\Module\ResourceModule;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ModuleFactory
{
    public static function getInstance(string $namespace): QueryRepositoryModule
    {
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule{
            protected function configure(): void
            {
                $this->bind(CacheItemPoolInterface::class)->annotatedWith(Shared::class)->to(ArrayAdapter::class)->in(Scope::SINGLETON);
                $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class)->in(Scope::SINGLETON);
            }
        });

        return $module;
    }
}
