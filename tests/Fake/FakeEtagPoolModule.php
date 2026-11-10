<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/** Gives ResourceStorage an ETag pool apart from the resource pool */
class FakeEtagPoolModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toConstructor(
            TagAwareAdapter::class,
            ['itemsPool' => EtagPool::class],
        )->in(Scope::SINGLETON);
    }
}
