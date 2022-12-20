<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Ray\Di\AbstractModule;

/**
 *  Provides HttpCacheInterface and derived bindings
 *
 *  The following bindings are provided:
 *
 *  HttpCacheInterface
 *  -Commands
 *  RefreshInterceptor
 *  StorageExpiryModule
 */
final class CacheableModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(HttpCacheInterface::class)->to(HttpCache::class);
        $this->bind()->annotatedWith(Commands::class)->toProvider(CommandsProvider::class);
        $this->bind(RefreshInterceptor::class);
        $this->install(new StorageExpiryModule(60, 60 * 60, 60 * 60 * 24));
        $this->installAopModule();
    }

    protected function installAopModule(): void
    {
        $this->bindPriorityInterceptor(
            $this->matcher->annotatedWith(Cacheable::class),
            $this->matcher->startsWith('onGet'),
            [CacheInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->annotatedWith(Cacheable::class),
            $this->matcher->logicalOr(
                $this->matcher->startsWith('onPut'),
                $this->matcher->logicalOr(
                    $this->matcher->startsWith('onPatch'),
                    $this->matcher->startsWith('onDelete'),
                ),
            ),
            [CommandInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->logicalNot(
                $this->matcher->annotatedWith(Cacheable::class),
            ),
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(Purge::class),
                $this->matcher->annotatedWith(Refresh::class),
            ),
            [RefreshInterceptor::class],
        );

        $this->bindInterceptor(
            $this->matcher->annotatedWith(AbstractCacheControl::class),
            $this->matcher->startsWith('onGet'),
            [HttpCacheInterceptor::class],
        );
    }
}
