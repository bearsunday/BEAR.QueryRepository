<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function assert;
use function get_class;
use function in_array;

class CommandInterceptor implements MethodInterceptor
{
    /** @var CommandInterface[] */
    private $commands = [];

    /**
     * @param CommandInterface[] $commands
     *
     * @Commands
     */
    #[Commands]
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
        assert(in_array($invocation->getMethod()->name, ['onPut', 'onDelete', 'onPatch']));
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException(get_class($invocation->getThis()));
        }

        if ($ro->code >= Code::BAD_REQUEST) {
            return $this;
        }

        foreach ($this->commands as $command) {
            $command->command($invocation, $ro);
        }

        return $ro;
    }
}
