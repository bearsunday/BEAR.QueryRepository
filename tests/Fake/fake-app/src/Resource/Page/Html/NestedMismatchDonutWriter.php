<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/**
 * A #[CacheableResponse] page whose write addresses onGet(), while onGet() itself makes a write that does not address its own
 */
#[CacheableResponse]
class NestedMismatchDonutWriter extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
    ) {
    }

    public function onGet(int $id): static
    {
        $this->resource->put('app://self/unmatch', ['id' => $id, 'age' => 10, 'name' => 'Sunday']);
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPost(int $id): static
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
