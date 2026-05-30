<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

/**
 * Interceptor for cache invalidation on CQRS commands with #[Purge] or #[Refresh]
 *
 * Automatically bound to all command methods (onPut/onPatch/onDelete) of #[Cacheable] classes.
 * Processes #[Purge] and #[Refresh] annotations on these methods and executes cache
 * invalidation after successful write operations.
 *
 * For non-Cacheable classes, use RefreshInterceptor instead by explicitly marking methods
 * with #[Purge] or #[Refresh].
 *
 * @see \BEAR\RepositoryModule\Annotation\Purge
 * @see \BEAR\RepositoryModule\Annotation\Refresh
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#tag-based-cache-invalidation
 */
final readonly class CommandInterceptor implements MethodInterceptor
{
    private RefreshTriggerLogger $refreshTriggerLogger;

    /** @param CommandInterface[] $commands */
    public function __construct(
        #[Commands]
        private array $commands,
        RepositoryLoggerInterface $logger = new NullRepositoryLogger(),
    ) {
        $this->refreshTriggerLogger = new RefreshTriggerLogger($logger);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ReturnValueIsNotResourceObjectException
     */
    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class);
        }

        if ($ro->code >= Code::BAD_REQUEST) {
            return $ro;
        }

        ($this->refreshTriggerLogger)($invocation, $ro);

        foreach ($this->commands as $command) {
            $command->command($invocation, $ro);
        }

        return $ro;
    }
}
