<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * Fails with the exact code the interceptor treats as the first error, so the donut
 * interceptor skips the put (logged as put_skipped with reason "error-code").
 */
#[CacheableResponse]
class ErrorPage extends ResourceObject
{
    public function onGet()
    {
        $this->code = Code::BAD_REQUEST;
        $this->body = ['error' => 'bad request'];

        return $this;
    }
}
