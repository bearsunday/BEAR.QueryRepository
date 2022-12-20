<?php

// phpcs:ignoreFile SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing -- for call_user_func_array

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionMethod;

use function array_values;
use function assert;
use function call_user_func_array;
use function get_class;
use function is_callable;
use function sprintf;

final class DonutCommandInterceptor implements MethodInterceptor
{
    public function __construct(
        private DonutRepositoryInterface $repository,
        private MatchQueryInterface $matchQuery
    ){
    }

    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        if ($ro->code >= Code::BAD_REQUEST) {
            return $ro;
        }

        $this->refreshDonutAndState($ro);

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
