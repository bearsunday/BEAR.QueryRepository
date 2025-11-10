<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\ProviderInterface;

/** @implements ProviderInterface<array<CommandInterface>> */
final readonly class CommandsProvider implements ProviderInterface
{
    public function __construct(
        private RefreshSameCommand $refreshSameCommand,
        private RefreshAnnotatedCommand $refreshAnnotatedCommand,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get()
    {
        return [
            $this->refreshSameCommand,
            $this->refreshAnnotatedCommand,
        ];
    }
}
