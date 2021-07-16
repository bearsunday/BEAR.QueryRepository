<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\RepositoryModule\Annotation\CacheVersion;
use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Backward Compatibility module
 *
 * Install this when you need deprecated interface.
 * (I don't think it' ever going to be needed, but just in case.)
 */
class BcModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(DeprecatedHttpCacheInterface::class)->to(HttpCache::class);
        /** @psalm-suppress DeprecatedClass */
        $this->bind(Cache::class)->annotatedWith(Storage::class)->toProvider(StorageProvider::class)->in(Scope::SINGLETON);
        /** @psalm-suppress DeprecatedClass */
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ArrayCache::class)->in(Scope::SINGLETON);
        $this->bind()->annotatedWith(CacheVersion::class)->toInstance('');
    }
}
