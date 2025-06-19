<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\ProviderInterface;

/** @implements ProviderInterface<array<CommandInterface>> */
final class CommandsProvider implements ProviderInterface
{
    public function __construct(
        private readonly RefreshSameCommand $refreshSameCommand,
        private readonly RefreshAnnotatedCommand $refreshAnnotatedCommand,
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
