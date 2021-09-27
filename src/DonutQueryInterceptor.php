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
    /** @var DonutRepositoryInterface */
    private $donutRepository;

    public function __construct(DonutRepositoryInterface $donutRepository)
    {
        $this->donutRepository = $donutRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        try {
            $maybeRo = $this->donutRepository->get($ro);
            if ($maybeRo instanceof ResourceObject) {
                return $maybeRo;
            }
        } catch (Throwable $e) {
            // when cache server is down
            $this->triggerWarning($e);

            return $invocation->proceed(); // @codeCoverageIgnore
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
