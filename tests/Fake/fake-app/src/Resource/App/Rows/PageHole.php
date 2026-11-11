<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A method-level #[CacheableResponse] page whose uncached list is left to render as a hole */
class PageHole extends ResourceObject
{
    public static int $runs = 0;

    #[CacheableResponse]
    #[Embed(rel: 'list', src: 'app://self/rows/plain?channelId=c1')]
    public function onGet(): static
    {
        self::$runs++;
        $this->body['page'] = true;

        return $this;
    }
}
