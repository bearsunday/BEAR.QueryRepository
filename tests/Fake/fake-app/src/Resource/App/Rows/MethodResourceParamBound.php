<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** The same, with #[ResourceParam] on the method naming the parameter */
#[Cacheable]
class MethodResourceParamBound extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    #[ResourceParam(uri: 'app://self/user?id=1#name', param: 'channelId')]
    public function onGet(string $channelId = 'c9'): static
    {
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
