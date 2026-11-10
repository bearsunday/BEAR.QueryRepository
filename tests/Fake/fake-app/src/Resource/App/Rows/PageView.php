<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A class-level #[CacheableResponse] page that reads its embedded list eagerly, making it a
 * material instead of a hole (the established CacheableResponseWithMaterial.php pattern)
 */
#[CacheableResponse]
class PageView extends ResourceObject
{
    public static int $runs = 0;

    #[Embed(rel: 'rows', src: 'app://self/rows/plain?article_id=1')]
    public function onGet(): static
    {
        self::$runs++;
        $this->body['touch'] = (string) $this->body['rows'];

        return $this;
    }
}
