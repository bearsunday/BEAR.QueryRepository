<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** A reader with no cache declaration: what it reads reaches the parent that stores it */
class Child extends ResourceObject
{
    public function onGet(#[ResourceParam(uri: 'app://self/user?id=1#name')] string $owner): static
    {
        $this->body = ['v' => $owner];

        return $this;
    }
}
