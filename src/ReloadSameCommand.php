<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Exception\LogicException;
use Ray\Aop\MethodInvocation;

class ReloadSameCommand implements CommandInterface
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
            if (isset($query[$parameter->name])) {
                $getQuery[$parameter->name] = $query[$parameter->name];
                continue;
            }
            throw new LogicException(get_class($resourceObject));
        }

        return $getQuery;
    }
}
