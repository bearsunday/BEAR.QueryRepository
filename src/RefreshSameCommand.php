<?php

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;
use ReflectionException;
use ReflectionMethod;
use function array_values;
use function call_user_func_array;
use function get_class;
use function is_callable;
use function sprintf;

// phpcs:ignoreFile SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing -- for call_user_func_array

final class RefreshSameCommand implements CommandInterface
{
    /** @var QueryRepositoryInterface */
    private $repository;
    /** @var MatchQueryInterface */
    private $matchQuery;

    public function __construct(QueryRepositoryInterface $repository, MatchQueryInterface $matchQuery)
    {
        $this->repository = $repository;
        $this->matchQuery = $matchQuery;
    }

    /**
     * @return void
     */
    public function command(MethodInvocation $invocation, ResourceObject $ro)
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
            call_user_func_array($get, array_values($getQuery));
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
