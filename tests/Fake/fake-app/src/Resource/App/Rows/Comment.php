<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** The row-dependency target: a write to one id busts only that id's own cache entry */
#[Cacheable]
class Comment extends ResourceObject
{
    public function onGet(string $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }
}
