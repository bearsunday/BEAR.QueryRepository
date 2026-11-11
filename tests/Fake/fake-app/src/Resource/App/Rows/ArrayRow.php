<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\ArrayUlid;

/** A row class whose template variable is an array */
#[Cacheable]
class ArrayRow extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [new ArrayUlid(['a1'])];

        return $this;
    }
}
