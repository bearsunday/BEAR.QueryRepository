<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
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
     * @var EtagSetterInterface
     */
    private $setEtag;

    /**
     * @var AnnotationReader
     */
    private $reader;

    /**
     * @param QueryRepositoryInterface $repository
     * @param EtagSetterInterface      $setEtag
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        EtagSetterInterface $setEtag,
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
        (string) $resourceObject;
        $this->setEtag->__invoke($resourceObject);
        $this->repository->put($resourceObject);

        return $resourceObject;
    }
}
