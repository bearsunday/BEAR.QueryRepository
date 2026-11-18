<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** The articles of a category, whose ID shares the name of the article ID: category 3 has none */
#[Cacheable]
class Category extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}', key: 'articles')]
    public function onGet(int $id): static
    {
        $this->body['articles'] = $id === 3 ? [] : [['id' => 1], ['guide' => 'see also'], ['id' => 2]];

        return $this;
    }
}
