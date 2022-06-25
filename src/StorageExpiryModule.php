<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;

final class StorageExpiryModule extends AbstractModule
{
    private int $short;

    private int $medium;

    private int $long;

    public function __construct(int $short, int $medium, int $long, ?AbstractModule $module = null)
    {
        $this->short = $short;
        $this->medium = $medium;
        $this->long = $long;
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
