<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use Ray\Di\ProviderInterface;

class CommandsProvider implements ProviderInterface
{
    /**
     * @var CommandInterface[]
     */
    private $commands = [];

    /**
     * @param RefreshSameCommand $command1
     */
    public function __construct(RefreshSameCommand $command1)
    {
        $this->commands = [
            $command1,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return new Commands($this->commands);
    }
}
