<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

/**
 * Interceptor for cache invalidation on CQRS commands with #[Purge] or #[Refresh]
 *
 * Bound to all command methods of #[Cacheable] classes. For non-Cacheable classes, use
 * RefreshInterceptor instead, marking methods explicitly with #[Purge] or #[Refresh].
 *
 * @see \BEAR\RepositoryModule\Annotation\Purge
 * @see \BEAR\RepositoryModule\Annotation\Refresh
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#tag-based-cache-invalidation
 * @internal
 */
final readonly class CommandInterceptor implements MethodInterceptor
{
    /** @param CommandInterface[] $commands */
    public function __construct(
        #[Commands]
        private array $commands,
        private RefreshTemplateValidator $refreshTemplateValidator,
        private CommandScope $scope,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws ReturnValueIsNotResourceObjectException
     */
    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        ($this->refreshTemplateValidator)($invocation);
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class);
        }

        $this->scope->run($invocation, $ro, 'CommandInterceptor', function () use ($invocation, $ro): void {
            foreach ($this->commands as $command) {
                $command->command($invocation, $ro);
            }
        });

        return $ro;
    }
}
