<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Annotation\IsOptimizeCache;
use Ray\Di\AbstractModule;

/**
 * Provides Cache and derived bindings
 *
 * The following bindings are provided:
 *
 * #[IsOptimizeCache]
 */
final class CacheOptimizeModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind()->annotatedWith(IsOptimizeCache::class)->toInstance(true);
    }
}
