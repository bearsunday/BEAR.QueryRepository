<?php

namespace FakeVendor\DemoApp;

use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\Resource\Module\ResourceModule;
use Ray\Di\AbstractModule;

class AppModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->install(new ResourceModule(__NAMESPACE__));
        $this->install(new QueryRepositoryModule);
    }
}