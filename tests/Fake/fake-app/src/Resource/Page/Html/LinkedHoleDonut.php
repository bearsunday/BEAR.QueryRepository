<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Method;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/** A #[DonutCache] page whose hole is a request that follows a link */
#[DonutCache]
class LinkedHoleDonut extends ResourceObject
{
    public function __construct(private readonly ResourceInterface $resource)
    {
    }

    public function onGet(string $link): static
    {
        $author = $this->resource->newRequest(Method::GET, 'app://self/linked/author', ['id' => 1]);
        $this->body = [
            'hole' => match ($link) {
                'self' => $author->linkSelf('blog'),
                'new' => $author->linkNew('blog'),
                default => $author->linkCrawl('tree'),
            },
        ];

        return $this;
    }
}
