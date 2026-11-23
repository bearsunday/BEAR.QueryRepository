<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function assert;

/**
 * Interceptor for donut cache invalidation on CQRS commands
 *
 * Bound to command methods (onPost/onPut/onPatch/onDelete) of classes marked with
 * #[CacheableResponse].
 *
 * @see \BEAR\RepositoryModule\Annotation\CacheableResponse
 * @see \BEAR\RepositoryModule\Annotation\DonutCache
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 * @internal
 */
final readonly class DonutCommandInterceptor implements MethodInterceptor
{
    public function __construct(
        private RefreshSameCommand $refreshSameCommand,
        private CommandScope $scope,
    ) {
    }

    #[Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);

        $this->scope->run($invocation, $ro, 'DonutCommandInterceptor', function () use ($invocation, $ro): void {
            try {
                $this->refreshSameCommand->command($invocation, $ro);
            } catch (UnmatchedQuery $e) {
                // Thrown by a write the rebuilding GET made
                $this->scope->skipUnmatched($invocation, $ro, $e);
            }
        });

        return $ro;
    }
}
