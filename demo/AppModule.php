<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp;

use BEAR\QueryRepository\DevQueryRepositoryLogModule;
use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function sys_get_temp_dir;

class AppModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith('storage_dir')->toInstance(__DIR__ . '/tmp')->in(Scope::SINGLETON);
        $this->install(new ResourceModule(__NAMESPACE__));
        // Recording is off by default. The log module wraps the one it turns recording on for,
        // the same way DevEtagModule wraps it: an install() cannot replace a binding the
        // installer already holds. An app passes its own directory, e.g. $appDir . '/var/log'.
        $logDir = sys_get_temp_dir() . '/bear-query-repository-demo-log';
        $this->install(new DevQueryRepositoryLogModule($logDir, module: new QueryRepositoryModule()));
        // Real in-memory pools: QueryRepositoryModule's default is a NullAdapter,
        // under which every demo GET would miss and the log could never show a hit.
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
        $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
    }
}
