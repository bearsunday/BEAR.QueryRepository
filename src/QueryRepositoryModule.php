<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Ray\PsrCacheModule\Psr6ArrayModule;

final class QueryRepositoryModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->install(new Psr6ArrayModule());
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
