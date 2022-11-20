<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

final class RefreshInterceptor implements MethodInterceptor
{
    public function __construct(
        private RefreshAnnotatedCommand $command,
    ) {
    }

    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class); // @codeCoverageIgnore
        }

        if ($ro->code < Code::BAD_REQUEST) {
            $this->command->command($invocation, $ro);
        }

        return $ro;
    }
}
