<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list of array rows under a key of the body */
#[Cacheable]
class RowList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}', key: 'articles')]
    public function onGet(): static
    {
        $this->body['articles'] = [['id' => 1, 'title' => 'aaa'], ['id' => 2, 'title' => 'bbb']];

        return $this;
    }
}
