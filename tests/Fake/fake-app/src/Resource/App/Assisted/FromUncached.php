<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** Reads its argument from a resource that is not cached and moves on every run */
#[Cacheable]
class FromUncached extends ResourceObject
{
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/counter#call', param: 'call')] int $call): static
    {
        $this->body = ['v' => $id . ':' . $call];

        return $this;
    }
}
