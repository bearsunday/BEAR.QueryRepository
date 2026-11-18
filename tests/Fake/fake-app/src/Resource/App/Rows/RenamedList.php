<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

use function array_column;

/** The same list read as rows: their IDs go in the body under a key of another name than the query's */
#[Cacheable]
class RenamedList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={ids*}')]
    public function onGet(): static
    {
        $rows = [['id' => 1, 'title' => 'one'], ['id' => 2, 'title' => 'two'], ['id' => 3, 'title' => 'three'], ['id' => 5, 'title' => 'five']];
        $this->body = ['items' => $rows, 'ids' => array_column($rows, 'id')];

        return $this;
    }
}
