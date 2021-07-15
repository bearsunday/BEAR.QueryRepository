<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\RepositoryModule\Annotation\CacheVersion;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Ray\PsrCacheModule\Psr6ArrayModule;

class QueryRepositoryModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->install(new Psr6ArrayModule());
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        $this->bind(EtagSetterInterface::class)->to(EtagSetter::class)->in(Scope::SINGLETON);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class)->in(Scope::SINGLETON);
        $this->bind(HttpCacheInterface::class)->to(HttpCache::class);
        $this->bind()->annotatedWith(Commands::class)->toProvider(CommandsProvider::class);
        $this->bind()->annotatedWith(CacheVersion::class)->toInstance('');
        $this->bind(RefreshInterceptor::class);
        $this->bind(ResourceStorageInterface::class)->to(ResourceStorage::class);
        $this->install(new QueryRepositoryAopModule());
        $this->install(new StorageExpiryModule(60, 60 * 60, 60 * 60 * 24));
        // BC
        $this->bind(DeprecatedHttpCacheInterface::class)->to(HttpCache::class);
        /** @psalm-suppress DeprecatedClass */
        $this->bind(Cache::class)->annotatedWith(Storage::class)->toProvider(StorageProvider::class)->in(Scope::SINGLETON);
        /** @psalm-suppress DeprecatedClass */
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ArrayCache::class)->in(Scope::SINGLETON);
    }
}
