<?php

// phpcs:ignoreFile SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing -- for call_user_func_array

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\AbstractUri;
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

class DonutCommandInterceptor implements MethodInterceptor
{
    /** @var DonutRepositoryInterface */
    private $repository;

    public function __construct(DonutRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        $this->refreshDonutAndState($ro);

        return $ro;
    }

    public function refreshDonutAndState(ResourceObject $ro): void
    {
        $getQuery = $this->getQuery($ro);
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

    /**
     * @return array<string, mixed>
     */
    private function getQuery(ResourceObject $ro): array
    {
        $refParameters = (new ReflectionMethod(get_class($ro), 'onGet'))->getParameters();
        $getQuery = [];
        $query = $ro->uri->query;
        foreach ($refParameters as $parameter) {
            if (! isset($query[$parameter->name])) {
                throw new UnmatchedQuery(sprintf('%s %s', $ro->uri->method, (string) $ro->uri));
            }

            /** @psalm-suppress MixedAssignment */
            $getQuery[$parameter->name] = $query[$parameter->name];
        }

        return $getQuery;
    }
}
