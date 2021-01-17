<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use Ray\Di\AbstractModule;

class QueryRepositoryAopModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bindPriorityInterceptor(
            $this->matcher->annotatedWith(Cacheable::class),
            $this->matcher->startsWith('onGet'),
            [CacheInterceptor::class]
        );
        $this->bindInterceptor(
            $this->matcher->annotatedWith(Cacheable::class),
            $this->matcher->logicalOr(
                $this->matcher->startsWith('onPost'),
                $this->matcher->logicalOr(
                    $this->matcher->startsWith('onPut'),
                    $this->matcher->logicalOr(
                        $this->matcher->startsWith('onPatch'),
                        $this->matcher->startsWith('onDelete')
                    )
                )
            ),
            [CommandInterceptor::class]
        );
        $this->bindInterceptor(
            $this->matcher->logicalNot(
                $this->matcher->annotatedWith(Cacheable::class)
            ),
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(Purge::class),
                $this->matcher->annotatedWith(Refresh::class)
            ),
            [RefreshInterceptor::class]
        );

        $this->bindInterceptor(
            $this->matcher->annotatedWith(AbstractCacheControl::class),
            $this->matcher->startsWith('onGet'),
            [HttpCacheInterceptor::class]
        );
    }
}
