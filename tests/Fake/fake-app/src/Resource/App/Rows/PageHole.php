<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page that renders its declared, uncached list as a hole: it never reads it in onGet() */
#[CacheableResponse]
class PageHole extends ResourceObject
{
    public static int $runs = 0;

    #[Embed(rel: 'rows', src: 'app://self/rows/plain?article_id=1')]
    public function onGet(): static
    {
        self::$runs++;
        $this->body['article'] = '1';

        return $this;
    }
}
