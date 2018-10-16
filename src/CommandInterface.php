<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
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
    public function command(MethodInvocation $invocation, ResourceObject $ro);
}
