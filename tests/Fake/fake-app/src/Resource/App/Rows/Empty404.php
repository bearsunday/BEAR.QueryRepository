<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** An uncached list that answers 404 with no body when it has no rows */
class Empty404 extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    #[DependsOn(uri: 'app://self/rows/shelf')]
    public function onGet(string $channelId): static
    {
        unset($channelId);
        $this->code = 404;

        return $this;
    }
}
