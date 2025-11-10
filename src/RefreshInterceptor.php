<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

/**
 * Interceptor for cache refresh commands with #[Purge] or #[Refresh]
 *
 * Bound to methods marked with #[Purge] or #[Refresh] on non-Cacheable classes.
 * Executes cache invalidation commands after successful method execution.
 *
 * @see \BEAR\RepositoryModule\Annotation\Purge
 * @see \BEAR\RepositoryModule\Annotation\Refresh
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 */
final readonly class RefreshInterceptor implements MethodInterceptor
{
    public function __construct(
        private RefreshAnnotatedCommand $command,
    ) {
    }

    #[Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class); // @codeCoverageIgnore
        }

        if ($ro->code < Code::BAD_REQUEST) {
            $this->command->command($invocation, $ro);
        }

        return $ro;
    }
}
