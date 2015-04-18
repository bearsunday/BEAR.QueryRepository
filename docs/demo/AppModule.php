<?php

namespace FakeVendor\DemoApp;

use BEAR\QueryRepository\Expiry;
use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\RepositoryModule\Annotation\ExpiryConfig;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Module\ResourceModule;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class AppModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        // Query repository engine
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ArrayCache::class)->in(Scope::SINGLETON);
        // Cache time
        list($short, $medium, $long) = [60, 3600, 24 * 3600];
        $this->bind()->annotatedWith(ExpiryConfig::class)->toInstance(new Expiry($short, $medium, $long));

        $this->install(new ResourceModule(__NAMESPACE__));
        $this->install(new QueryRepositoryModule);
    }
}
