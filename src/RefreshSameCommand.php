<?php

// Not strict_types: call_user_func_array() below is compiled as a direct call and takes this
// file's typing mode, and a string query value must still coerce into a typed onGet parameter.

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
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
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        unset($invocation);
        $getQuery = $this->getQuery($ro);
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
     */
    private function getQuery(ResourceObject $ro): array
    {
        return $this->matchQuery->__invoke($ro);
    }
}
