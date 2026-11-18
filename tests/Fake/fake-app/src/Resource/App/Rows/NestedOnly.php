<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** The only value of the variable is an array, and no argument has its name */
#[Cacheable]
class NestedOnly extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={user}')]
    public function onGet(): static
    {
        $this->body = ['user' => ['id' => 7]];

        return $this;
    }
}
