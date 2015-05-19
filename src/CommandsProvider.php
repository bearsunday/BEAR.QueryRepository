<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Composer\Repository\RepositoryInterface;
use Doctrine\Common\Annotations\Reader;
use Ray\Di\ProviderInterface;

class CommandsProvider implements ProviderInterface
{
    /**
     * @var RepositoryInterface
     */
    private $repository;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var ResourceInterface
     */
    private $resource;

    /**
     * @param QueryRepositoryInterface $repository
     * @param Reader                   $reader
     * @param ResourceInterface        $resource
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        Reader $reader,
        ResourceInterface $resource
    ) {
        $this->repository = $repository;
        $this->reader = $reader;
        $this->resource = $resource;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $commands = [
            new RefreshSameCommand($this->repository),
            new RefreshAnnotatedCommand($this->repository, $this->reader, $this->resource)
        ];

        return $commands;
    }
}
