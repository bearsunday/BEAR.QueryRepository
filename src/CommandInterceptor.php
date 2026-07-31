<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\QueryRepository\Log\NullSemanticLogger;
use BEAR\RepositoryModule\Annotation\Commands;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
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
    private CommandContextFactory $commandContextFactory;

    /** @param CommandInterface[] $commands */
    public function __construct(
        #[Commands]
        private array $commands,
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
        $this->commandContextFactory = new CommandContextFactory();
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

        // Open the scope even for a failed write: a 4xx command_result with no invalidation
        // events records that the purge/refresh was correctly skipped (symmetric with the
        // query side, which logs purge on non-200).
        $openId = $this->logger->open(($this->commandContextFactory)($invocation, 'CommandInterceptor'));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                foreach ($this->commands as $command) {
                    $command->command($invocation, $ro);
                }
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }
}
