<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCommand;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Ray\Aop\MethodInvocation;
use Ray\Aop\ReflectionMethod;

final class RefreshAnnotatedCommand implements CommandInterface
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
        /** @var ReflectionMethod $method */
        $method = $invocation->getMethod();
        $annotations = $method->getAnnotations();
        foreach ($annotations as $annotation) {
            $this->request($ro, $annotation);
        }
    }

    private function getUri(ResourceObject $ro, AbstractCommand $annotation) : string
    {
        $body = \is_array($ro->body) ? $ro->body : [];
        $query = $body + $ro->uri->query;

        return uri_template($annotation->uri, $query);
    }

    private function request(ResourceObject $ro, $annotation)
    {
        if (! $annotation instanceof AbstractCommand) {
            return;
        }
        $uri = new Uri($this->getUri($ro, $annotation));
        if ($annotation instanceof Purge) {
            $this->repository->purge($uri);
        }
        if ($annotation instanceof Refresh) {
            $this->repository->purge($uri);
            $ro = $this->resource->get((string) $uri);
            $this->repository->put($ro);
        }
    }
}
