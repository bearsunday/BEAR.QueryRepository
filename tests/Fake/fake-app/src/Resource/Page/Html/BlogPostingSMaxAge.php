<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\DonutRepositoryInterface;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** Stores itself with a shared-cache lifetime */
#[CacheableResponse]
class BlogPostingSMaxAge extends ResourceObject
{
    public function __construct(
        private readonly DonutRepositoryInterface $repository,
    ) {
    }

    #[Embed(rel: 'comment', src: 'page://self/html/comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';
        $this->repository->putStatic($this, null, 10);

        return $this;
    }
}
