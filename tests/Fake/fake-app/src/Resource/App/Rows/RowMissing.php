<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A variable that is in neither the rows, the body's top level nor the arguments */
#[Cacheable]
class RowMissing extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/article?ulid={ulid}', key: 'articles')]
    public function onGet(): static
    {
        $this->body['articles'] = [['id' => 1]];

        return $this;
    }
}
