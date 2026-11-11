<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Catalog;

/** A method-level #[CacheableResponse] list */
class Mr extends ResourceObject
{
    #[CacheableResponse]
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body = ['entries' => Catalog::items($channelId), 'channelId' => $channelId];

        return $this;
    }
}
