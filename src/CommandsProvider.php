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
     * @param ReloadSameCommand      $command1
     * @param ReloadAnnotatedCommand $command2
     */
    public function __construct(
        ReloadSameCommand $command1
//        ReloadAnnotatedCommand $command2
    ) {
        $this->commands = [
            $command1,
//            $command2
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
