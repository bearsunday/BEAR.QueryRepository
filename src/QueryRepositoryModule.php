<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\NullAdapter;

final class QueryRepositoryModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        // Null cache default
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(Shared::class)->to(NullAdapter::class)->in(Scope::SINGLETON);
        // core
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        $this->bind(CacheDependencyInterface::class)->to(CacheDependency::class);
        $this->bind(EtagSetterInterface::class)->to(EtagSetter::class)->in(Scope::SINGLETON);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class)->in(Scope::SINGLETON);
        $this->bind(ResourceStorageInterface::class)->to(ResourceStorage::class);
        $this->bind(MatchQueryInterface::class)->to(MatchQuery::class)->in(Scope::SINGLETON);
        $this->bind(UriTag::class)->in(Scope::SINGLETON);
        // #[Cacheable]
        $this->install(new CacheableModule());
        // #[CacheableResponse]
        $this->install(new DonutCacheModule());
    }
}
