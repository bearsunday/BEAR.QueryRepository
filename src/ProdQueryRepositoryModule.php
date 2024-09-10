<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\KnownTagTtl;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\LocalCacheProvider;
use Symfony\Component\Cache\Adapter\AdapterInterface;

final class ProdQueryRepositoryModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     *
     * @see https://github.com/symfony/cache/blob/5.3/Adapter/TagAwareAdapter.php
     */
    protected function configure(): void
    {
        // Bind ResourceObjectPool to local cache (APCu or File)
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toProvider(LocalCacheProvider::class);
        // boost the performance of symfony/cache
        $this->bind()->annotatedWith(KnownTagTtl::class)->toInstance(0.15);
    }
}
