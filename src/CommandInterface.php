<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;

/**
 * Command
 *
 * Change resource state
 */
interface CommandInterface
{
    /**
     * @param MethodInvocation<object> $invocation
     *
     * @return void
     */
    public function command(MethodInvocation $invocation, ResourceObject $ro);
}
