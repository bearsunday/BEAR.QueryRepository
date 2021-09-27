<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\DonutCache;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class DonutAopModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
        $this->bindPriorityInterceptor(
            $this->matcher->annotatedWith(DonutCache::class),
            $this->matcher->startsWith('onGet'),
            [DonutQueryInterceptor::class]
        );

        $this->bindInterceptor(
            $this->matcher->annotatedWith(DonutCache::class),
            $this->matcher->logicalOr(
                $this->matcher->startsWith('onPut'),
                $this->matcher->logicalOr(
                    $this->matcher->startsWith('onPatch'),
                    $this->matcher->startsWith('onDelete')
                )
            ),
            [DonutCommandInterceptor::class]
        );
    }
}
