<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** The body is an assoc array, not a list: #[DependsOn] must refuse it */
#[Cacheable]
class KeyedBody extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(): static
    {
        $this->body = ['comments' => [['id' => '1']]];

        return $this;
    }
}
