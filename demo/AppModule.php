<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp;

use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AppModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith('storage_dir')->toInstance(__DIR__ . '/tmp')->in(Scope::SINGLETON);
        $this->install(new ResourceModule(__NAMESPACE__));
        $this->install(new QueryRepositoryModule);
        // Real in-memory pools: QueryRepositoryModule's default is a NullAdapter,
        // under which every demo GET would miss and the log could never show a hit.
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
        $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
    }
}
