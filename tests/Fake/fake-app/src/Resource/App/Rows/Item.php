<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A dependency target: a write to one ID invalidates that URI */
#[Cacheable]
class Item extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPut(int $id): static
    {
        unset($id);

        return $this;
    }
}
