<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\RepositoryModule\Annotation\TagsPool;
use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use Override;
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
    #[Override]
    protected function configure(): void
    {
        // Null cache engine default
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(NullAdapter::class);
        // When null is bound, the same adapter as the one assigned to the ResourceObjectPool is used.
        $this->bind(AdapterInterface::class)->annotatedWith(TagsPool::class)->toInstance(null);
        // TagAwareAdapterInterface is injected into ResourceStorage
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
            TagAwareAdapter::class,
            [
                'itemsPool' => ResourceObjectPool::class,
                'tagsPool' => TagsPool::class,
            ],
        );
        //  When null is bound, the same adapter as the one assigned to the ResourceObjectPool is used.
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance(null);
        // core
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        // CacheDependency is likewise wrapped by a logging decorator; the undecorated
        // dependency resolver is bound under 'origin'.
        $this->bind(CacheDependencyInterface::class)->annotatedWith('origin')->to(CacheDependency::class);
        $this->bind(CacheDependencyInterface::class)->to(LoggableCacheDependency::class);
        $this->bind(EtagSetterInterface::class)->to(EtagSetter::class);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class);
        // The storage is wrapped by a logging decorator: cache writes are emitted as
        // semantic-log events in LoggableResourceStorage, keeping ResourceStorage itself
        // free of any logging concern. The undecorated storage is bound under 'origin'.
        $this->bind(ResourceStorageInterface::class)->annotatedWith('origin')->to(ResourceStorage::class)->in(Scope::SINGLETON);
        $this->bind(ResourceStorageInterface::class)->to(LoggableResourceStorage::class)->in(Scope::SINGLETON);
        $this->bind(MatchQueryInterface::class)->to(MatchQuery::class);
        $this->bind(RefreshAnnotatedCommand::class);
        $this->bind(RefreshSameCommand::class);
        $this->bind(ResourceStorageSaver::class);
        $this->bind(CacheTags::class);
        $this->bind(CommandContextFactory::class);
        // Server context for thread safety (Swoole, RoadRunner, etc.)
        $this->bind(ServerContextInterface::class)->to(GlobalServerContext::class)->in(Scope::SINGLETON);
        // #[Cacheable]
        $this->install(new CacheableModule());
        // #[CacheableResponse]
        $this->install(new DonutCacheModule());
    }
}
