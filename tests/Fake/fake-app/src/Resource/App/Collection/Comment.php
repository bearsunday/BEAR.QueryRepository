<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Collection;

use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

class Comment extends ResourceObject
{
    #[Link(rel: 'collection', href: '/collection/posts')]
    #[Link(rel: 'collection', href: '')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPut(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }
}
