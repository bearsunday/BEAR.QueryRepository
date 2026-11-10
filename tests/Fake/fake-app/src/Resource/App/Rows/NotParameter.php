<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** An onGet() declaration whose variable is a key of the rows, not a parameter */
#[Cacheable]
class NotParameter extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
    public function onGet(string $channelId): static
    {
        $this->body = [['ulid' => 'a1'], ['ulid' => $channelId]];

        return $this;
    }
}
