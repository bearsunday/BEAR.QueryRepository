<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** An onGet() declaration whose variable #[ResourceParam] binds, and that onGet() puts in the body */
#[Cacheable]
class BoundInBody extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(#[ResourceParam(uri: 'app://self/user?id=1#name', param: 'channelId')] string $channelId = 'c9'): static
    {
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
