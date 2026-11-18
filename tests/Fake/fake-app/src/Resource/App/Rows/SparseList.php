<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list of IDs that is empty or holds a null */
#[Cacheable]
class SparseList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item{?id*}')]
    public function onGet(string $shape): static
    {
        $this->body = ['id' => $shape === 'empty' ? [] : [1, null]];

        return $this;
    }
}
