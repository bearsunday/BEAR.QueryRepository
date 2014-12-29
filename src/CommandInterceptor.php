<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use Doctrine\Common\Annotations\Reader;
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
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        Reader $reader
    ) {
        $this->commands = [
            new ReloadSameCommand($repository),
            new ReloadAnnotatedCommand($repository, $reader)
        ];
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
