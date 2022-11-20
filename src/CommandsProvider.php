<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Ray\Di\ProviderInterface;

/** @implements ProviderInterface<array<CommandInterface>> */
final class CommandsProvider implements ProviderInterface
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private ResourceInterface $resource,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return [
            new RefreshSameCommand($this->repository, new MatchQuery()),
            new RefreshAnnotatedCommand($this->repository, $this->resource),
        ];
    }
}
