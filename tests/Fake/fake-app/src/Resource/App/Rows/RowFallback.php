<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** Variables the rows lack, read from the body's top level and from the arguments */
#[Cacheable]
class RowFallback extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelUlid}', key: 'articles')]
    #[DependsOn(uri: 'app://self/rows/article?ulid={channelId}-{id}', key: 'articles')]
    public function onGet(string $channelId): static
    {
        $this->body = ['articles' => [['id' => 1], ['id' => 2]], 'channelUlid' => 'c1'];

        return $this;
    }
}
