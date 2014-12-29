<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class QueryInterceptor implements MethodInterceptor
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    /**
     * @var SetEtagInterface
     */
    private $setEtag;

    /**
     * @param QueryRepositoryInterface $repository
     * @param SetEtagInterface            $setEtag
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        SetEtagInterface $setEtag
    ) {
        $this->repository = $repository;
        $this->setEtag = $setEtag;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $resourceObject = $invocation->getThis();
        /** @var $resourceObject ResourceObject */
        $stored = $this->repository->get($resourceObject->uri);
        if ($stored) {
            list($resourceObject->code, $resourceObject->headers, $resourceObject->body) = $stored;

            return $resourceObject;
        }
        $resourceObject = $invocation->proceed();
        $this->setEtag->__invoke($resourceObject);
        $this->repository->put($resourceObject);

        return $resourceObject;
    }
}
