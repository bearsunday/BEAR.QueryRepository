<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** An uncached resource that embeds another uncached one without reading it, so that one runs only as it renders */
class LazyMid extends ResourceObject
{
    #[Embed(rel: 'inner', src: 'app://self/rows/plain{?channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body['channelId'] = $channelId;

        return $this;
    }
}
