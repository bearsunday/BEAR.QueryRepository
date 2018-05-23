<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class CommandInterceptor implements MethodInterceptor
{
    /**
     * @var CommandInterface[]
     */
    private $commands = [];

    /**
     * @param CommandInterface[] $commands
     *
     * @Commands
     */
    public function __construct(array $commands)
    {
        $this->commands = $commands;
    }

    /**
     * {@inheritdoc}
     *
     * @throws ReturnValueIsNotResourceObjectException
     */
    public function invoke(MethodInvocation $invocation)
    {
        $resourceObject = $invocation->proceed();
        if (! $resourceObject instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException(\get_class($invocation->getThis()));
        }
        foreach ($this->commands as $command) {
            $command->command($invocation, $resourceObject);
        }

        return $resourceObject;
    }
}
