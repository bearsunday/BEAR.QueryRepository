<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A row that is a scalar, not an array: #[DependsOn] must refuse it */
#[Cacheable]
class ScalarRow extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(): static
    {
        $this->body = ['1', '3'];

        return $this;
    }
}
