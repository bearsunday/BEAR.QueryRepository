<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Entry;

/** One row object under a key of the body */
#[Cacheable]
class Detail extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item{?id}', key: 'detail')]
    public function onGet(): static
    {
        $this->body = ['detail' => new Entry(3, 'ccc')];

        return $this;
    }
}
