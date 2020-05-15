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
            $this->triggerError($e);

            return $invocation->proceed();
        }
        if ($stored) {
            [$ro->uri, $ro->code, $ro->headers, $ro->body, $ro->view] = $stored;

            return $ro;
        }
        $ro = $invocation->proceed();
        try {
            $ro->code === 200 ? $this->repository->put($ro) : $this->repository->purge($ro->uri);
        } catch (LogicException | RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->triggerError($e);
        }

        return $ro;
    }

    /**
     * Trigger error when cache server is down instead of throwing the exception
     */
    private function triggerError(\Exception $e) : void
    {
        $message = sprintf('%s: %s in %s:%s', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        trigger_error($message, E_USER_WARNING);
    }
}
