<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use Throwable;

use function assert;
use function get_class;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

class DonutQueryInterceptor implements MethodInterceptor
{
    /** @var QueryRepositoryInterface  */
    private $queryRepository;

    /** @var DonutRepository */
    private $donutRepository;

    public function __construct(
        DonutRepository $donutRepository,
        QueryRepositoryInterface $queryRepository
    ) {
        $this->donutRepository = $donutRepository;
        $this->queryRepository = $queryRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        try {
            $maybeState = $this->queryRepository->get($ro->uri);
        } catch (Throwable $e) {
            // when cache server is down
            $this->triggerWarning($e);

            return $invocation->proceed(); // @codeCoverageIgnore
        }

        if ($maybeState instanceof ResourceState) {
            $ro->headers = $maybeState->headers;
            $ro->view = $maybeState->view;

            return $ro;
        }

        // refresh state
        $maybeStatic = $this->donutRepository->refreshDonut($ro);
        if ($maybeStatic instanceof ResourceObject) {
            return $maybeStatic;
        }

        /** @var ResourceObject $ro */
        $ro = $invocation->proceed();

        return $this->donutRepository->createDonut($ro, null);
    }

    /**
     * @codeCoverageIgnore
     */
    private function triggerWarning(Throwable $e): void
    {
        trigger_error(sprintf('%s: %s in %s:%s', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()), E_USER_WARNING);
    }
}
