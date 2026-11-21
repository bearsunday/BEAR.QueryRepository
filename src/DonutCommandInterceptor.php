<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
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
        private UnsafeMethodMatcher $unsafeMethod,
        private CommandContextFactory $commandContextFactory,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
    ) {
    }

    #[Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);

        // Open the scope even for a failed write: a 4xx command_result with no invalidation
        // events records that the donut purge/refresh was correctly skipped.
        $openId = $this->logger->open(($this->commandContextFactory)($invocation, 'DonutCommandInterceptor'));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                try {
                    $this->refreshSameCommand->command($invocation, $ro);
                } catch (UnmatchedQuery $e) {
                    // From a write the rebuilding GET made: the POST already ran, so it is recorded. Other methods throw.
                    if (! $this->unsafeMethod->isPost($invocation->getMethod())) {
                        throw $e;
                    }

                    // Without the query, which holds the values the write was sent
                    $this->logger->event(new CacheErrorContext("{$ro->uri->scheme}://{$ro->uri->host}{$ro->uri->path}", 'write', $e->getMessage(), $e::class));
                }
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }
}
