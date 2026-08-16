<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * Presets its own ETag in onGet, so the donut interceptor intentionally skips
 * the put (logged as put_skipped with reason "etag-present").
 */
#[CacheableResponse]
class SelfEtag extends ResourceObject
{
    public function onGet(int $id = 0)
    {
        $this->body = [
            'article' => '1',
        ];
        $this->headers[Header::ETAG] = '"self-etag"';

        return $this;
    }
}
