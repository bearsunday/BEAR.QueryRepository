<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A row whose value is an array, not a scalar: #[DependsOn] must refuse it */
#[Cacheable]
class ArrayValueRow extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(): static
    {
        $this->body = [['id' => ['nested']]];

        return $this;
    }
}
