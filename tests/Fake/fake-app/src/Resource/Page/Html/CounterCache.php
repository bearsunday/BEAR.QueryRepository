<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * Same shape as CounterDonut, but `#[CacheableResponse]` at the class level (not
 * `#[DonutCache]`) - proves DonutCacheModule::installAopClassModule()'s own
 * DonutCacheableResponseInterceptor path reads an already-read embed from the same single
 * execution too, not only the `#[DonutCache]` path.
 */
#[CacheableResponse]
class CounterCache extends ResourceObject
{
    /** @var array<string, mixed> */
    public $body = ['label' => 'counter-cache'];

    #[Embed(rel: 'counter', src: 'app://self/counter')]
    public function onGet(): static
    {
        $this->body['read'] = $this->body['counter']['call'];

        return $this;
    }
}
