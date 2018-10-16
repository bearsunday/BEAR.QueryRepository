<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class CacheInterceptor implements MethodInterceptor
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    public function __construct(
        QueryRepositoryInterface $repository
    ) {
        $this->repository = $repository;
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
        /* @var Cacheable $cacheable */
        try {
            /* @var ResourceObject $ro */
            $ro = $invocation->proceed();
            $ro->code === 200 ? $this->repository->put($ro) : $this->repository->purge($ro->uri);
        } catch (\Exception $e) {
            $this->repository->purge($ro->uri);

            throw $e;
        }

        return $ro;
    }
}
