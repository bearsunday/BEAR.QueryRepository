<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheEngine;
use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\ApcuCache;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * @deprecated
 *
 * Use \Ray\PsrCacheModule\Psr6ApcuModule
 */
class StorageApcModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        /** @psalm-suppress DeprecatedClass */
        $this->bind(CacheProvider::class)->annotatedWith(CacheEngine::class)->to(ApcuCache::class);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->toProvider(NamespacedCacheProvider::class)->in(Scope::SINGLETON);
    }
}
