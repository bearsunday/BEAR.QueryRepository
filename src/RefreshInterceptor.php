<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function get_class;

final class RefreshInterceptor implements MethodInterceptor
{
    /** @var RefreshAnnotatedCommand */
    private $command;

    public function __construct(RefreshAnnotatedCommand $command)
    {
        $this->command = $command;
    }

    /**
     * @return ResourceObject
     */
    public function invoke(MethodInvocation $invocation)
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException(get_class($invocation->getThis()));
        }

        if ($ro->code < Code::BAD_REQUEST) {
            $this->command->command($invocation, $ro);
        }

        return $ro;
    }
}
