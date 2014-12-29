<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;

class ReloadAnnotatedCommand implements CommandInterface
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    public function __construct(QueryRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param ResourceObject $resourceObject
     */
    public function command(MethodInvocation $invocation, ResourceObject $resourceObject)
    {
    }
}
