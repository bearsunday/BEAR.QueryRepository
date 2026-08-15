<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\RepositoryModule\Annotation\TagsPool;
use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
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
 *  SemanticLoggerInterface (null by default - install Dev/ProdQueryRepositoryLogModule to record)
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
        // Null cache log default: the log costs nothing and imposes no flush duty until an
        // app installs DevQueryRepositoryLogModule or ProdQueryRepositoryLogModule.
        $this->bind(SemanticLoggerInterface::class)->to(NullSemanticLogger::class);
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
        $this->bind(CacheDependencyInterface::class)->to(CacheDependency::class);
        $this->bind(EtagSetterInterface::class)->to(EtagSetter::class);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class);
        $this->bind(ResourceStorageInterface::class)->to(ResourceStorage::class)->in(Scope::SINGLETON);
        $this->bind(MatchQueryInterface::class)->to(MatchQuery::class);
        $this->bind(RefreshAnnotatedCommand::class);
        $this->bind(RefreshSameCommand::class);
        $this->bind(ResourceStorageSaver::class);
        // Server context for thread safety (Swoole, RoadRunner, etc.)
        $this->bind(ServerContextInterface::class)->to(GlobalServerContext::class)->in(Scope::SINGLETON);
        // #[Cacheable]
        $this->install(new CacheableModule());
        // #[CacheableResponse]
        $this->install(new DonutCacheModule());
    }
}
