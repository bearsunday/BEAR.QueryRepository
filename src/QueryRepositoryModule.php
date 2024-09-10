<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 *  Provides ResourceStorageInterface and derived bindings
 *
 *  The following bindings are provided:
 *
 *  CacheItemPoolInterface-Shared::class
 *  CacheItemPoolInterface-EtagPool::class
 *  QueryRepositoryInterface
 *  CacheDependencyInterface
 *  EtagSetterInterface
 *  NamedParameterInterface
 *  ResourceStorageInterface
 *  MatchQueryInterface
 *  UriTag
 *  MatchQueryInterface
 *
 *  The following module are installed.
 *
 *  CacheableModule
 *  DonutCacheModule
 */
final class QueryRepositoryModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        // Null cache engine default
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(NullAdapter::class);
        $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(NullAdapter::class);
        // Bind TagAwareAdapterInterface
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            TagAwareAdapter::class,
            [
                'itemsPool' => ResourceObjectPool::class,
                'tagsPool' => EtagPool::class,
            ],
        )->in(Scope::SINGLETON);
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance(null);
        // core
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        $this->bind(CacheDependencyInterface::class)->to(CacheDependency::class);
        $this->bind(EtagSetterInterface::class)->to(EtagSetter::class)->in(Scope::SINGLETON);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class)->in(Scope::SINGLETON);
        $this->bind(ResourceStorageInterface::class)->to(ResourceStorage::class);
        $this->bind(MatchQueryInterface::class)->to(MatchQuery::class)->in(Scope::SINGLETON);
        $this->bind(UriTag::class)->in(Scope::SINGLETON);
        $this->bind(RefreshAnnotatedCommand::class);
        $this->bind(ResourceStorageSaver::class);
        // #[Cacheable]
        $this->install(new CacheableModule());
        // #[CacheableResponse]
        $this->install(new DonutCacheModule());
    }
}
