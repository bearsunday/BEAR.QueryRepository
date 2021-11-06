<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\KnownTagTtl;
use Ray\Di\AbstractModule;

final class ProdQueryRepositoryModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     *
     * @see https://github.com/symfony/cache/blob/5.3/Adapter/TagAwareAdapter.php
     */
    protected function configure(): void
    {
        // boost the performance of symfony/cache
        $this->bind()->annotatedWith(KnownTagTtl::class)->toInstance(0.15);
    }
}
