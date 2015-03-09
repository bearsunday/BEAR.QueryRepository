<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
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
     * @param ReloadSameCommand $command1
     */
    public function __construct(ReloadSameCommand $command1)
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
