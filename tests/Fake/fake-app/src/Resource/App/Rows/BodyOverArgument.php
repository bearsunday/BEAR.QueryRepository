<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** The query names the channel by its slug; the body holds the ULID read for it */
#[Cacheable]
class BodyOverArgument extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body = ['channelId' => $channelId === 'news' ? 'c1' : $channelId];

        return $this;
    }
}
