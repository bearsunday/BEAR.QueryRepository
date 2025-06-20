<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;

final class StorageExpiryModule extends AbstractModule
{
    public function __construct(
        private readonly int $short,
        private readonly int $medium,
        private readonly int $long,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(Expiry::class)->toInstance(new Expiry($this->short, $this->medium, $this->long));
    }
}
