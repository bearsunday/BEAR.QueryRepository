<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\AbstractModule;

/**
 * Adds one key to every entry this package stores and to every Surrogate-Key it sets, so that invalidating the key,
 * on the server and on the CDN, invalidates the whole application. Install it before or after QueryRepositoryModule.
 */
final class AppSurrogateKeyModule extends AbstractModule
{
    public function __construct(
        private readonly string $key,
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
        $this->bind(AppSurrogateKey::class)->toInstance(new AppSurrogateKey($this->key));
    }
}
