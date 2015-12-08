<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCommand;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\Resource;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\Reader;
use Ray\Aop\MethodInvocation;

class RefreshAnnotatedCommand implements CommandInterface
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
     * @var Resource|ResourceInterface
     */
    private $resource;

    /**
     * @param QueryRepositoryInterface $repository
     * @param Reader                   $reader
     * @param ResourceInterface        $resource
     */
    public function __construct(
        QueryRepositoryInterface $repository,
        Reader $reader,
        Resource $resource
    ) {
        $this->repository = $repository;
        $this->reader = $reader;
        $this->resource = $resource;
    }

    /**
     * @param MethodInvocation $invocation
     * @param ResourceObject   $resourceObject
     */
    public function command(MethodInvocation $invocation, ResourceObject $resourceObject)
    {
        /* @var $purgeAnnotations Purge[] */
        $annotations = $this->reader->getMethodAnnotations($invocation->getMethod());
        foreach ($annotations as $annotation) {
            $this->request($resourceObject, $annotation);
        }
    }

    /**
     * @param ResourceObject $resourceObject
     * @param object         $annotation
     *
     * @return string
     */
    private function getUri(ResourceObject $resourceObject, AbstractCommand $annotation)
    {
        $body = is_array($resourceObject->body) ? $resourceObject->body : [];
        $query = $body + $resourceObject->uri->query;
        $uri = uri_template($annotation->uri, $query);

        return $uri;
    }

    /**
     * @param ResourceObject $resourceObject
     * @param object         $annotation
     */
    private function request(ResourceObject $resourceObject, $annotation)
    {
        if (!$annotation instanceof AbstractCommand) {
            return;
        }
        $uri = new Uri($this->getUri($resourceObject, $annotation));
        if ($annotation instanceof Purge) {
            $this->repository->purge($uri);
        }
        if ($annotation instanceof Refresh) {
            $this->repository->purge($uri);
            $ro = $this->resource->get->uri($uri)->eager->request();
            $this->repository->put($ro);
        }
    }
}
