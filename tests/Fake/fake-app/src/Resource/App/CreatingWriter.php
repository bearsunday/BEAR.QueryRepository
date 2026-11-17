<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** No cache attribute: its writes do not carry the id its onGet() requires, as a write that creates an entity */
class CreatingWriter extends ResourceObject
{
    public function onGet(string $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[Purge(uri: 'page://self/dep/level-two')]
    public function onPost(): static
    {
        $this->code = 201;

        return $this;
    }

    public function onPut(): static
    {
        return $this;
    }
}
