<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\PrivateUlid;

/** A row class whose template variable is a private property */
#[Cacheable]
class PrivateRow extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [new PrivateUlid('a1')];

        return $this;
    }
}
