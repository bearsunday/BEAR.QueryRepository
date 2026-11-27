<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AppSurrogateKey;
use Override;
use Ray\Di\AbstractModule;

/**
 * Adds one key to every entry this package stores and, as the first key, to the Surrogate-Key of each response it stores or
 * writes as a donut, so that invalidating the key, on the server and on the CDN, invalidates the whole application. Install it
 * before or after QueryRepositoryModule.
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
        $this->bind()->annotatedWith(AppSurrogateKey::class)->toInstance($this->key);
    }
}
