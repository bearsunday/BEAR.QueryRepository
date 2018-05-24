<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\Reader;
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
     * @var Reader
     */
    private $reader;

    public function __construct(
        QueryRepositoryInterface $repository,
        EtagSetterInterface $setEtag,
        Reader $annotationReader
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
        /** @var ResourceObject $ro */
        $ro = $invocation->getThis();
        $stored = $this->repository->get($ro->uri);
        if ($stored) {
            list($ro->uri, $ro->code, $ro->headers, $ro->body, $ro->view) = $stored;

            return $ro;
        }
        /* @var $cacheable Cacheable */
        try {
            /** @var ResourceObject $ro */
            $ro = $invocation->proceed();
            $ro->code === 200 ? $this->repository->put($ro) : $this->repository->purge($ro->uri);
        } catch (\Exception $e) {
            $this->repository->purge($ro->uri);
            throw $e;
        }

        return $ro;
    }
}
