<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

final class RefreshInterceptor implements MethodInterceptor
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
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException(\get_class($invocation->getThis()));
        }
        $this->command->command($invocation, $ro);

        return $ro;
    }
}
