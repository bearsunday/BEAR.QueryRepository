<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Commands;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class CommandInterceptor implements MethodInterceptor
{
    /**
     * @var CommandInterface[]
     */
    private $commands = [];

    /**
     * @param QueryRepositoryInterface $repository
     *
     * @Commands
     */
    public function __construct(array $commands)
    {
        $this->commands = $commands;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $resourceObject = $invocation->proceed();
        foreach ($this->commands as $command) {
            $command->command($invocation, $resourceObject);
        }

        return $resourceObject;
    }
}
