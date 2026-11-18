<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A body that is an empty list of rows, without a key */
#[Cacheable]
class EmptyBody extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    public function onGet(string $channelId): static
    {
        unset($channelId);
        $this->body = [];

        return $this;
    }
}
