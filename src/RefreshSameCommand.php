<?php

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;
use ReflectionException;
use ReflectionMethod;

use function array_values;
use function assert;
use function call_user_func_array;
use function get_class;
use function in_array;
use function is_callable;
use function sprintf;
use function var_dump;

// phpcs:ignoreFile SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing -- for call_user_func_array

final class RefreshSameCommand implements CommandInterface
{
    /** @var QueryRepositoryInterface */
    private $repository;

    public function __construct(QueryRepositoryInterface $repository)
    {
        $this->repository = $repository;
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
