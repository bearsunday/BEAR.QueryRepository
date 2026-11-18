<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** An empty list of rows, and a template the arguments fill */
#[Cacheable]
class EmptyRows extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    #[DependsOn(uri: 'app://self/rows/item?id={id}', key: 'articles')]
    public function onGet(string $channelId): static
    {
        unset($channelId);
        $this->body['articles'] = [];

        return $this;
    }
}
