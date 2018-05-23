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
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Ray\Aop\MethodInvocation;

class RefreshAnnotatedCommand implements CommandInterface
{
    /**
     * @var QueryRepositoryInterface
     */
    private $repository;

    /**
     * @var ResourceInterface
     */
    private $resource;

    public function __construct(
        QueryRepositoryInterface $repository,
        ResourceInterface $resource
    ) {
        $this->repository = $repository;
        $this->resource = $resource;
    }

    public function command(MethodInvocation $invocation, ResourceObject $ro)
    {
        $annotations = $invocation->getMethod()->getAnnotations();
        foreach ($annotations as $annotation) {
            $this->request($ro, $annotation);
        }
    }

    private function getUri(ResourceObject $resourceObject, AbstractCommand $annotation) : string
    {
        $body = \is_array($resourceObject->body) ? $resourceObject->body : [];
        $query = $body + $resourceObject->uri->query;
        $uri = uri_template($annotation->uri, $query);

        return $uri;
    }

    private function request(ResourceObject $resourceObject, $annotation)
    {
        if (! $annotation instanceof AbstractCommand) {
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
