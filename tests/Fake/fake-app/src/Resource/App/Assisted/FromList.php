<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** Reads a stored list that has a dependency of its own */
#[Cacheable]
class FromList extends ResourceObject
{
    public function onGet(#[ResourceParam(uri: 'app://self/rows/ca?channelId=c1#channelId')] string $channelId): static
    {
        $this->body = ['v' => $channelId];

        return $this;
    }
}
