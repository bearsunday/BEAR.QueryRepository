<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/**
 * #[HttpCache(noStore: true)] declares its own client-facing Cache-Control: no-store - the
 * server-side #[CacheableResponse] donut store and CDN headers must keep working regardless
 * (issue #222 regression guard).
 */
#[CacheableResponse, HttpCache(noStore: true)]
class CacheableResponseNoStore extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        $this->body['article'] = '1';

        return $this;
    }
}
