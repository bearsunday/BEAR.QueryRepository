<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Entry;
use FakeVendor\HelloWorld\Entity\EntryList;

/** Row objects in a list object under a key of the body */
#[Cacheable]
class EntryRows extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item{?id}', key: 'entries')]
    public function onGet(): static
    {
        $this->body = ['entries' => new EntryList([new Entry(1, 'aaa'), new Entry(2, 'bbb')]), 'count' => 2];

        return $this;
    }
}
