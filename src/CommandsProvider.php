<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Ray\Di\ProviderInterface;

class CommandsProvider implements ProviderInterface
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    /**
     * @var ResourceInterface
     */
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
        $commands = [
            new RefreshSameCommand($this->repository),
            new RefreshAnnotatedCommand($this->repository, $this->resource)
        ];

        return $commands;
    }
}
