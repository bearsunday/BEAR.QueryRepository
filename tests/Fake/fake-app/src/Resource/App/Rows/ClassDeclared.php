<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
#[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
class ClassDeclared extends ResourceObject
{
    public function onGet(string $channelId): static
    {
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
