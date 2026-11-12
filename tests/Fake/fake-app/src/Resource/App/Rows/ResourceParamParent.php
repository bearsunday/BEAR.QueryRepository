<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** An onGet() that takes channelId from another resource; a child class overrides it */
abstract class ResourceParamParent extends ResourceObject
{
    public function onGet(#[ResourceParam(uri: 'app://self/user?id=1#name', param: 'channelId')] string $channelId = 'c9'): static
    {
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
