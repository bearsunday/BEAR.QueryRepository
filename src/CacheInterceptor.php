<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\AnnotationReader;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class CacheInterceptor implements MethodInterceptor
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
     * @var AnnotationReader
     */
    private $reader;

    /**
     * @param QueryRepositoryInterface $repository
     * @param SetEtagInterface         $setEtag
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        SetEtagInterface $setEtag,
        AnnotationReader $annotationReader
    ) {
        $this->repository = $repository;
        $this->setEtag = $setEtag;
        $this->reader = $annotationReader;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $resourceObject = $invocation->getThis();
        /* @var $resourceObject ResourceObject */
        $stored = $this->repository->get($resourceObject->uri);
        if ($stored) {
            list($resourceObject->code, $resourceObject->headers, $resourceObject->body) = $stored;

            return $resourceObject;
        }
        /* @var $cacheable Cacheable */
        $resourceObject = $invocation->proceed();
        $this->setEtag->__invoke($resourceObject);
        $this->repository->put($resourceObject);

        return $resourceObject;
    }
}
