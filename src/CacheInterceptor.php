<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\LogicException;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use Throwable;

use function assert;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

final class CacheInterceptor implements MethodInterceptor
{
    public function __construct(
        private QueryRepositoryInterface $repository,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        try {
            $state = $this->repository->get($ro->uri);
        } catch (Throwable $e) {
            $this->triggerWarning($e);

            return $invocation->proceed(); // @codeCoverageIgnore
        }

        if ($state instanceof ResourceState) {
            $state->visit($ro);

            return $ro;
        }

        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        try {
            $ro->code === 200 ? $this->repository->put($ro) : $this->repository->purge($ro->uri);
        } catch (LogicException $e) {
            throw $e;
        } catch (Throwable $e) {  // @codeCoverageIgnore
            $this->triggerWarning($e); // @codeCoverageIgnore
        }

        return $ro;
    }

    /**
     * Trigger warning
     *
     * When the cache server is down, it will issue a warning rather than an exception to continue service.
     *
     * @codeCoverageIgnore
     */
    private function triggerWarning(Throwable $e): void
    {
        $message = sprintf('%s: %s in %s:%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        trigger_error($message, E_USER_WARNING);
    }
}
