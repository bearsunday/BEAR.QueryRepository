<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A method-level #[CacheableResponse] page that reads its uncached list */
class PageDonut extends ResourceObject
{
    public static int $runs = 0;

    #[CacheableResponse]
    #[Embed(rel: 'list', src: 'app://self/rows/plain?channelId=c1')]
    public function onGet(): static
    {
        self::$runs++;
        $this->body['read'] = (string) $this->body['list'];

        return $this;
    }
}
