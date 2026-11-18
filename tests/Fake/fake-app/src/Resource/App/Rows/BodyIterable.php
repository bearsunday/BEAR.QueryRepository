<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Entry;
use FakeVendor\HelloWorld\Entity\EntryList;

/** A list object that is the body itself */
#[Cacheable]
class BodyIterable extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    #[DependsOn(uri: 'app://self/rows/shelf')]
    public function onGet(): static
    {
        $this->body = new EntryList([new Entry(1, 'aaa'), new Entry(2, 'bbb')]);

        return $this;
    }
}
