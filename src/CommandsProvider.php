<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Ray\Di\ProviderInterface;

/** @implements ProviderInterface<array<CommandInterface>> */
final class CommandsProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryRepositoryInterface $repository,
        private readonly ResourceInterface $resource,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get()
    {
        return [
            new RefreshSameCommand($this->repository, new MatchQuery()),
            new RefreshAnnotatedCommand($this->repository, $this->resource),
        ];
    }
}
