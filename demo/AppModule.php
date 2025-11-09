<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp;

use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\Resource\Module\ResourceModule;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class AppModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith('storage_dir')->toInstance(__DIR__ . '/tmp')->in(Scope::SINGLETON);
        //        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toConstructor(FilesystemCache::class, 'directory=storage_dir')->in(Scope::SINGLETON);
        $this->install(new ResourceModule(__NAMESPACE__));
        $this->install(new QueryRepositoryModule);
    }
}
