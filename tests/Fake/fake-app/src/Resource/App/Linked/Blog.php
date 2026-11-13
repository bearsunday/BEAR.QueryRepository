<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Linked;

use BEAR\Resource\ResourceObject;

class Blog extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['blog' => $id];

        return $this;
    }
}
