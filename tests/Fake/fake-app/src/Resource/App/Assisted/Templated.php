<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** The request query names the resource #[ResourceParam] reads */
#[Cacheable]
class Templated extends ResourceObject
{
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user{?id}#name', templated: true)] string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }
}
