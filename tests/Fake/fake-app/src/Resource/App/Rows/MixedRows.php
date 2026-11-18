<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list of article rows with a row of another kind between them, which has no ID */
#[Cacheable]
class MixedRows extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}', key: 'articles')]
    public function onGet(): static
    {
        $this->body['articles'] = [['id' => 1, 'title' => 'aaa'], ['guide' => 'see also'], ['id' => 2, 'title' => 'bbb']];

        return $this;
    }
}
