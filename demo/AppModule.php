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

use function bin2hex;
use function random_bytes;
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
        // installer already holds. An app passes a directory of its own, e.g.
        // $appDir . '/var/log/query-repository' - a dedicated one, since the writer prunes what
        // it finds there. The demo writes to a random temp path so nothing lands at a name a
        // local user could pre-create.
        $logDir = sys_get_temp_dir() . '/bear-query-repository-demo-' . bin2hex(random_bytes(6));
        $this->install(new DevQueryRepositoryLogModule($logDir, module: new QueryRepositoryModule()));
        // Real in-memory pools: QueryRepositoryModule's default is a NullAdapter,
        // under which every demo GET would miss and the log could never show a hit.
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
        $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
    }
}
