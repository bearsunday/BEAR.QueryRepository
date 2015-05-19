<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;

class RefreshSameCommand implements CommandInterface
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    public function __construct(QueryRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param MethodInvocation $invocation
     * @param ResourceObject   $resourceObject
     */
    public function command(MethodInvocation $invocation, ResourceObject $resourceObject)
    {
        unset($invocation);
        $onGet = [$resourceObject, 'onGet'];
        $getQuery = $this->getQuery($resourceObject, $onGet);
        $delUri = clone $resourceObject->uri;
        $delUri->query = $getQuery;

        // delete data in repository
        $this->repository->purge($delUri);

        // GET for re-generate (in interceptor)
        $resourceObject->uri->query = $getQuery;
        call_user_func_array($onGet, $getQuery);
    }

    /**
     * @param ResourceObject $resourceObject
     *
     * @return array
     */
    private function getQuery(ResourceObject $resourceObject)
    {
        $refParameters = (new \ReflectionMethod($resourceObject, 'onGet'))->getParameters();
        $getQuery = [];
        $query = $resourceObject->uri->query;
        foreach ($refParameters as $parameter) {
            if (! isset($query[$parameter->name])) {
                throw new UnmatchedQuery($resourceObject->uri->method . ' ' . (string) $resourceObject->uri);
            }
            $getQuery[$parameter->name] = $query[$parameter->name];
        }

        return $getQuery;
    }
}
