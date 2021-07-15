<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class FakeEtagPoolModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class)->in(Scope::SINGLETON);
    }
}
