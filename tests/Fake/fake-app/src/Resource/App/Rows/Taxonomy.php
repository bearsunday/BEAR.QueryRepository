<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A dependency target: a write to one ULID invalidates that URI */
#[Cacheable]
class Taxonomy extends ResourceObject
{
    public function onGet(string $ulid): static
    {
        $this->body = ['ulid' => $ulid];

        return $this;
    }

    public function onPut(string $ulid): static
    {
        unset($ulid);

        return $this;
    }
}
