<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Item;

/** A null argument and a row without a series name no URI */
#[Cacheable]
class NullValues extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string|null $channelId = null): static
    {
        $this->body = ['channelId' => $channelId, 'entries' => [new Item('a1', null), new Item('a3', 's1')]];

        return $this;
    }
}
