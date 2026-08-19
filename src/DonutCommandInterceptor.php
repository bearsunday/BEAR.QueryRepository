<?php

// phpcs:ignoreFile SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing -- for call_user_func_array

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use BEAR\RepositoryModule\Annotation\CacheLog;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionMethod;

use function array_values;
use function assert;
use function call_user_func_array;
use function get_class;
use function is_callable;
use function sprintf;

/**
 * Interceptor for donut cache invalidation on CQRS commands
 *
 * Bound to command methods (onPut/onPatch/onDelete) of classes marked with #[CacheableResponse].
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
        private SemanticLoggerInterface $logger = new NullSemanticLogger()
    ){
        $this->commandContextFactory = new CommandContextFactory();
    }

    #[\Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);

        // Open the scope even for a failed write: a 4xx command_result with no invalidation
        // events records that the donut purge/refresh was correctly skipped.
        $openId = $this->logger->open(($this->commandContextFactory)($invocation, 'DonutCommandInterceptor'));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                $this->refreshDonutAndState($ro);
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }

    public function refreshDonutAndState(ResourceObject $ro): void
    {
        $getQuery =($this->matchQuery)($ro);
        $delUri = clone $ro->uri;
        $delUri->query = $getQuery;

        // purge donut, resource state cache and etag
        $this->repository->purge($delUri);
        // update donut and create resource state
        $this->refresh($getQuery, $ro);
    }

    /**
     * @param array<string, mixed> $getQuery
     */
    private function refresh(array $getQuery, ResourceObject $ro): void
    {
        $ro->uri->query = $getQuery;
        $get = [$ro, 'onGet'];
        if (is_callable($get)) {
            call_user_func_array($get, array_values($getQuery));
        }
    }
}
