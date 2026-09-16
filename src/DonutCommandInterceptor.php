<?php

// Not strict_types: call_user_func_array() below is compiled as a direct call and takes this
// file's typing mode, and a string query value must still coerce into a typed onGet parameter.

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function assert;
use function call_user_func_array;
use function is_callable;
use function str_starts_with;

/**
 * Interceptor for donut cache invalidation on CQRS commands
 *
 * Bound to command methods (onPost/onPut/onPatch/onDelete) of classes marked with #[CacheableResponse].
 * Refreshes donut cache and resource state after successful write operations.
 *
 * @see \BEAR\RepositoryModule\Annotation\CacheableResponse
 * @see \BEAR\RepositoryModule\Annotation\DonutCache
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 */
final readonly class DonutCommandInterceptor implements MethodInterceptor
{
    private CommandContextFactory $commandContextFactory;

    public function __construct(
        private DonutRepositoryInterface $repository,
        private MatchQueryInterface $matchQuery,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
        $this->commandContextFactory = new CommandContextFactory();
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
                    $this->refreshDonutAndState($ro);
                } catch (UnmatchedQuery $e) {
                    // Same shape as RefreshSameCommand::command() on the value-cache side (#219):
                    // an onPost-bound class that creates rather than addresses an entity routinely
                    // lacks a parameter onGet requires. The write already ran; there is no entry
                    // to refresh, so the purge/refresh is skipped and recorded instead of thrown.
                    // onPut/onPatch/onDelete keep throwing - a mismatch there addresses an entity
                    // onGet already does, so it is a real signature mismatch, not this case.
                    if (! str_starts_with($invocation->getMethod()->getName(), 'onPost')) {
                        throw $e;
                    }

                    $this->logger->event(new CacheErrorContext((string) $ro->uri, 'write', $e->getMessage(), $e::class));
                }
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }

    public function refreshDonutAndState(ResourceObject $ro): void
    {
        $getQuery = ($this->matchQuery)($ro);
        $delUri = clone $ro->uri;
        $delUri->query = $getQuery;

        // purge donut, resource state cache and etag
        $this->repository->purge($delUri);
        // update donut and create resource state
        $this->refresh($getQuery, $ro);
    }

    /** @param array<string, mixed> $getQuery */
    private function refresh(array $getQuery, ResourceObject $ro): void
    {
        $ro->uri->query = $getQuery;
        $get = [$ro, 'onGet'];
        if (is_callable($get)) {
            // String keys are named arguments: a parameter MatchQuery omitted keeps its
            // default instead of shifting the positional order.
            call_user_func_array($get, $getQuery);
        }
    }
}
