<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Collection;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class AuthorPosts extends ResourceObject
{
    public function onGet(int $authorId): static
    {
        $this->body = ['author_id' => $authorId];

        return $this;
    }
}
