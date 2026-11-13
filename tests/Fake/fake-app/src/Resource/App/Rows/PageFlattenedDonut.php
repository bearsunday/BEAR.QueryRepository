<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** PageDonut over a list whose renderer turns its rows into arrays */
class PageFlattenedDonut extends ResourceObject
{
    #[CacheableResponse]
    #[Embed(rel: 'list', src: 'app://self/rows/flattened-list?channelId=c1')]
    public function onGet(): static
    {
        $this->body['read'] = (string) $this->body['list'];

        return $this;
    }
}
