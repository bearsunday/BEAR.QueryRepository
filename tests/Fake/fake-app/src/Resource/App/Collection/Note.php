<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Collection;

use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

class Note extends ResourceObject
{
    #[Link(rel: 'collection', href: 'x/collection/posts')]
    #[Link(rel: 'collection', href: ':80/collection/posts')]
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
