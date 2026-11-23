<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Item;
use FakeVendor\HelloWorld\Entity\Ulid;

/** A row whose ULID is a Stringable */
#[Cacheable]
class StringableUlid extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={seriesId}')]
    public function onGet(): static
    {
        $this->body = [new Item(new Ulid('a1'), 's1')];

        return $this;
    }
}
