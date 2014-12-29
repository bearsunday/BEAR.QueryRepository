<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Reload;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\Reader;
use Ray\Aop\MethodInvocation;

class ReloadAnnotatedCommand implements CommandInterface
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @param QueryRepositoryInterface $repository
     * @param Reader                   $reader
     * @param ResourceInterface        $resource
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        Reader $reader
    ) {
        $this->repository = $repository;
        $this->reader = $reader;
    }

    /**
     * @param MethodInvocation $invocation
     * @param ResourceObject   $resourceObject
     */
    public function command(MethodInvocation $invocation, ResourceObject $resourceObject)
    {
        /** @var $purgeAnnotations Purge[] */
        $annotations = $this->reader->getMethodAnnotations($invocation->getMethod());
        foreach($annotations as $annotation) {
            if ($annotation instanceof Purge) {
                $uri = uri_template($annotation->uri, $resourceObject->body);
                $this->repository->purge(new Uri($uri));
            }
            if ($annotation instanceof Reload) {
                $uri = uri_template($annotation->uri, $resourceObject->body);
                $this->repository->purge(new Uri($uri));
            }
        }
    }
}
