<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Collection;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Post extends ResourceObject
{
    #[Link(rel: 'collection', href: 'app://self/collection/posts')]
    #[Link(rel: 'author', href: 'app://self/collection/author-posts{?author_id}')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'author_id' => 7];

        return $this;
    }

    public function onPut(int $id, int $authorId): static
    {
        $this->body = ['id' => $id, 'author_id' => $authorId];

        return $this;
    }

    #[Purge(uri: 'app://self/collection/author-posts?author_id={old_author_id}')]
    #[Purge(uri: 'app://self/collection/author-posts?author_id={author_id}')]
    public function onPatch(int $id, int $authorId): static
    {
        $this->body = ['id' => $id, 'author_id' => $authorId, 'old_author_id' => 7];

        return $this;
    }

    public function onDelete(int $id): static
    {
        unset($id);
        $this->code = 204;

        return $this;
    }
}
