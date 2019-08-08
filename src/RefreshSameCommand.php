<?php

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use function is_callable;
use Ray\Aop\MethodInvocation;

final class RefreshSameCommand implements CommandInterface
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    public function __construct(QueryRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function command(MethodInvocation $invocation, ResourceObject $ro)
    {
        $method = $invocation->getMethod()->getName();
        if ($method === 'onGet' || $method === 'onPost') {
            return;
        }
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
            /**
             * @psalm-suppress InvalidArgument
             */
            \call_user_func_array($get, $getQuery);
        }
    }

    /**
     * @throws \ReflectionException
     */
    private function getQuery(ResourceObject $ro) : array
    {
        $refParameters = (new \ReflectionMethod(\get_class($ro), 'onGet'))->getParameters();
        $getQuery = [];
        $query = $ro->uri->query;
        foreach ($refParameters as $parameter) {
            if (! isset($query[$parameter->name])) {
                throw new UnmatchedQuery(sprintf('%s %s', $ro->uri->method, (string) $ro->uri));
            }
            $getQuery[$parameter->name] = $query[$parameter->name];
        }

        return $getQuery;
    }
}
