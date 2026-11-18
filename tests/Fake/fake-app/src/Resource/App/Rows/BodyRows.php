<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list of array rows that is the body itself */
#[Cacheable]
class BodyRows extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    #[DependsOn(uri: 'app://self/rows/shelf')]
    public function onGet(): static
    {
        $this->body = [['id' => 1, 'title' => 'aaa'], ['id' => 2, 'title' => 'bbb']];

        return $this;
    }
}
