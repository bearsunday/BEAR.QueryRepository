<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\LogicException;
use BEAR\QueryRepository\Exception\RuntimeException;
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
        try {
            $stored = $this->repository->get($ro->uri);
        } catch (LogicException | RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->errorLog($e);

            return $invocation->proceed();
        }
        if ($stored) {
            list($ro->uri, $ro->code, $ro->headers, $ro->body, $ro->view) = $stored;

            return $ro;
        }
        try {
            $ro = $invocation->proceed();
            $ro->code === 200 ? $this->repository->put($ro) : $this->repository->purge($ro->uri);
        } catch (LogicException | RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->errorLog($e);
        }

        return $ro;
    }

    private function errorLog(\Exception $e) : void
    {
        $message = sprintf('%s: %s in %s:%s', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        syslog(LOG_CRIT, $message);
    }
}
