<?php

// Not strict_types: call_user_func_array() below is compiled as a direct call and takes this
// file's typing mode, and a string query value must still coerce into a typed onGet parameter.

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInvocation;
use ReflectionException;

use function call_user_func_array;
use function is_callable;

final readonly class RefreshSameCommand implements CommandInterface
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private MatchQueryInterface $matchQuery,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        try {
            $getQuery = $this->getQuery($ro);
        } catch (UnmatchedQuery $e) {
            // #214 bound this command to onPost too, on classes that create rather than address
            // an existing entity - onGet's required parameters (an id assigned on write) are
            // routinely absent from a POST's own query. Pre-#214, onPost reached no command at
            // all, so this was silently a no-op; skipping here restores that shape instead of a
            // regression, and an explicit #[Refresh]/#[Purge] on the method still runs next
            // (CommandsProvider orders this command first, but returning - not throwing - lets
            // CommandInterceptor's loop reach the next command).
            //
            // onPut/onPatch/onDelete keep throwing: those act on an entity onGet already
            // addresses, so a required parameter missing there is a real signature mismatch, not
            // this case (BehaviorTest::testUnMatchQuery pins that).
            if ($invocation->getMethod()->getName() !== 'onPost') {
                throw $e;
            }

            $this->logger->event(new CacheErrorContext((string) $ro->uri, 'write', $e->getMessage(), $e::class));

            return;
        }

        $delUri = clone $ro->uri;
        $delUri->query = $getQuery;

        // delete data in repository
        $this->repository->purge($delUri);

        // GET for re-generate (in interceptor)
        $ro->uri->query = $getQuery;
        $get = [$ro, 'onGet'];
        if (is_callable($get)) {
            // String keys are named arguments: a parameter MatchQuery omitted keeps its
            // default instead of shifting the positional order.
            call_user_func_array($get, $getQuery);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReflectionException
     * @throws UnmatchedQuery
     */
    private function getQuery(ResourceObject $ro): array
    {
        return $this->matchQuery->__invoke($ro);
    }
}
