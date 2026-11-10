<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** An uncached resource that embeds and reads another uncached one */
class Outer extends ResourceObject
{
    #[Embed(rel: 'inner', src: 'app://self/rows/mid{?channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body['channelId'] = $channelId;
        $this->body['read'] = (string) $this->body['inner'];

        return $this;
    }
}
