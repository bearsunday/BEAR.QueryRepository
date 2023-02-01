<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

final class CommandInterceptor implements MethodInterceptor
{
    /** @param CommandInterface[] $commands */
    public function __construct(
        #[Commands]
        private array $commands,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @throws ReturnValueIsNotResourceObjectException
     */
    public function invoke(MethodInvocation $invocation)
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class);
        }

        if ($ro->code >= Code::BAD_REQUEST) {
            return $ro;
        }

        foreach ($this->commands as $command) {
            $command->command($invocation, $ro);
        }

        return $ro;
    }
}
