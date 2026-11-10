<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Catalog;

/** A class-level #[Cacheable] list: the channel from its argument, the rows from their class */
#[Cacheable]
class Ca extends ResourceObject
{
    public static int $runs = 0;

    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        self::$runs++;
        $this->body = ['entries' => Catalog::items($channelId), 'channelId' => $channelId];

        return $this;
    }

    public function onPost(string $channelId, string $ulid): static
    {
        Catalog::$channels[$channelId][] = [$ulid, 's9'];

        return $this;
    }
}
