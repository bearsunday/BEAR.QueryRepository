<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

/** No cache attribute: its write changes its own URI, as a handler that addresses what it created does */
class UriChangingWriter extends ResourceObject
{
    public function onGet(string $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPut(string $id): static
    {
        $this->uri->query = ['id' => $id . '-changed'];

        return $this;
    }
}
