<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\LogicException;
use BEAR\QueryRepository\Exception\RuntimeException;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use Throwable;

use function assert;
use function get_class;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

class CacheInterceptor implements MethodInterceptor
{
    /** @var QueryRepositoryInterface */
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
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        try {
            $stored = $this->repository->get($ro->uri);
        } catch (LogicException | RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->triggerError($e);

            return $invocation->proceed();
        }

        if ($stored) {
            [$ro->uri, $ro->code, $ro->headers, $ro->body, $ro->view] = $stored;

            return $ro;
        }

        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        try {
            $ro->code === 200 ? $this->repository->put($ro) : $this->repository->purge($ro->uri);
        } catch (LogicException | RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->triggerError($e);
        }

        return $ro;
    }

    /**
     * Trigger error when cache server is down instead of throwing the exception
     */
    private function triggerError(Throwable $e): void
    {
        $message = sprintf('%s: %s in %s:%s', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        trigger_error($message, E_USER_WARNING);
    }
}
