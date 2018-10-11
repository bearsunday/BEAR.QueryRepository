<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class RefreshInterceptor implements MethodInterceptor
{
    /**
     * @var RefreshAnnotatedCommand 
     */
    private $command;

    public function __construct(RefreshAnnotatedCommand $command)
    {
        $this->command = $command;
    }

    public function invoke(MethodInvocation $invocation)
    {
        $resourceObject = $invocation->proceed();
        if (! $resourceObject instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException(\get_class($invocation->getThis()));
        }
        $this->command->command($invocation, $resourceObject);

        return $resourceObject;
    }
}
