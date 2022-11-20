<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;

final class StorageExpiryModule extends AbstractModule
{
    public function __construct(
        private int $short,
        private int $medium,
        private int $long,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(Expiry::class)->toInstance(new Expiry($this->short, $this->medium, $this->long));
    }
}
