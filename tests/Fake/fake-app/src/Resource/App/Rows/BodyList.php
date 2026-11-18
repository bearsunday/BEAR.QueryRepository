<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list whose items are known only once it is read: onGet() puts their IDs in the body */
#[Cacheable]
class BodyList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item{?id*}')]
    public function onGet(): static
    {
        $this->body = ['id' => [1, 2, 3, 5]];

        return $this;
    }
}
