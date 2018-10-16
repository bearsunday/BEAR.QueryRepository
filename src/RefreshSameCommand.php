<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
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
        if (\method_exists($ro, 'onGet')) {
            \call_user_func_array([$ro, 'onGet'], $getQuery);
        }
    }

    /**
     * @throws \ReflectionException
     */
    private function getQuery(ResourceObject $resourceObject) : array
    {
        $refParameters = (new \ReflectionMethod(\get_class($resourceObject), 'onGet'))->getParameters();
        $getQuery = [];
        $query = $resourceObject->uri->query;
        foreach ($refParameters as $parameter) {
            if (! isset($query[$parameter->name])) {
                throw new UnmatchedQuery(sprintf('%s %s', $resourceObject->uri->method, (string) $resourceObject->uri));
            }
            $getQuery[$parameter->name] = $query[$parameter->name];
        }

        return $getQuery;
    }
}
