<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\QueryRepository\Log\NullSemanticLogger;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

/**
 * Interceptor for cache refresh commands with #[Purge] or #[Refresh]
 *
 * Bound only to methods explicitly marked with #[Purge] or #[Refresh] on non-Cacheable classes.
 * Unlike CommandInterceptor (which automatically binds to all command methods of #[Cacheable]
 * classes), this interceptor requires explicit attribute annotation on each method.
 *
 * Executes cache invalidation commands after successful method execution.
 *
 * @see \BEAR\RepositoryModule\Annotation\Purge
 * @see \BEAR\RepositoryModule\Annotation\Refresh
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 */
final readonly class RefreshInterceptor implements MethodInterceptor
{
    private CommandContextFactory $commandContextFactory;

    public function __construct(
        private RefreshAnnotatedCommand $command,
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
        $this->commandContextFactory = new CommandContextFactory();
    }

    #[Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class); // @codeCoverageIgnore
        }

        // Open the scope even for a failed write: a 4xx command_result with no invalidation
        // events records that the purge/refresh was correctly skipped.
        $openId = $this->logger->open(($this->commandContextFactory)($invocation, 'RefreshInterceptor'));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                $this->command->command($invocation, $ro);
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }
}
