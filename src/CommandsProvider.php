<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Ray\Di\ProviderInterface;

/**
 * @implements ProviderInterface<array<CommandInterface>>
 */
final class CommandsProvider implements ProviderInterface
{
    /** @var QueryRepositoryInterface */
    private $repository;

    /** @var ResourceInterface */
    private $resource;

    public function __construct(
        QueryRepositoryInterface $repository,
        ResourceInterface $resource
    ) {
        $this->repository = $repository;
        $this->resource = $resource;
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
