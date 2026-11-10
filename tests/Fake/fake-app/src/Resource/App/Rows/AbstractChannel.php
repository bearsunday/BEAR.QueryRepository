<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

abstract class AbstractChannel extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
