<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;

interface CommandInterface
{
    public function command(MethodInvocation $invocation, ResourceObject $resourceObject);
}
