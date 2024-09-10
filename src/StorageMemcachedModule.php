<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\RepositoryModule\Annotation\TagsPool;
use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use Ray\PsrCacheModule\MemcachedAdapter;
use Ray\PsrCacheModule\Psr6MemcachedModule;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

final class StorageMemcachedModule extends AbstractModule
{
    /** @param string $servers 'mem1.domain.com:11211:33,mem2.domain.com:11211:67' {host}:{port}:{weight} */
    public function __construct(
        private readonly string $servers,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(MemcachedAdapter::class);
        $this->install(new Psr6MemcachedModule($this->servers));
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            TagAwareAdapter::class,
            [
                'itemsPool' => ResourceObjectPool::class,
                'tagsPool' => TagsPool::class,
                'namespace' => CacheNamespace::class,
            ],
        );
        $this->bind(AdapterInterface::class)->to(MemcachedAdapter::class);
//        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->toConstructor(MemcachedAdapter::class, [
//            'namespace' => CacheNamespace::class,
//        ]);
    }
}
