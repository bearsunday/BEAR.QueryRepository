<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** Without a key, the variable is a top-level key of the body */
#[Cacheable]
class TopLevelId extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    public function onGet(): static
    {
        $this->body['id'] = 5;

        return $this;
    }
}
