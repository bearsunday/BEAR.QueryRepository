<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * CounterDonut with class-level #[CacheableResponse]
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
