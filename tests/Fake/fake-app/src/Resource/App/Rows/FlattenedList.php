<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\QueryRepository\FakeRowFlatteningRenderer;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Catalog;

/** Plain, rendered by a renderer that turns its rows into arrays */
class FlattenedList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body = ['entries' => Catalog::items($channelId), 'channelId' => $channelId];
        $this->setRenderer(new FakeRowFlatteningRenderer());

        return $this;
    }
}
