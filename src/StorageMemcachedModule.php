<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\PoolErrorLogger;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\RepositoryModule\Annotation\TagsPool;
use Override;
use Psr\Log\LoggerInterface;
use Ray\Di\AbstractModule;
use Ray\Di\InjectionPoints;
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
    #[Override]
    protected function configure(): void
    {
        $this->bind(LoggerInterface::class)->annotatedWith('poolError')->to(PoolErrorLogger::class);
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            MemcachedAdapter::class,
            [],
            // Memcached swallows backend failures the same way Redis does: without the cache log
            // in the adapter's hands, a store that is down is a run of ordinary misses.
            (new InjectionPoints())->addMethod('setLogger', 'poolError'),
        );
        $this->install(new Psr6MemcachedModule($this->servers));
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            TagAwareAdapter::class,
            [
                'itemsPool' => ResourceObjectPool::class,
                'tagsPool' => TagsPool::class,
                'namespace' => CacheNamespace::class,
            ],
            (new InjectionPoints())->addMethod('setLogger', 'poolError'),
        );
    }
}
