<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Collection;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Posts extends ResourceObject
{
    public function onGet(int|null $authorId = null, int $page = 1): static
    {
        $this->body = ['author_id' => $authorId, 'page' => $page];

        return $this;
    }
}
