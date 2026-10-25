<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * CounterDonut with method-level #[CacheableResponse] (bound by installAopMethodModule())
 */
class CounterCacheMethodLevel extends ResourceObject
{
    /** @var array<string, mixed> */
    public $body = ['label' => 'counter-cache-method-level'];

    #[CacheableResponse]
    #[Embed(rel: 'counter', src: 'app://self/counter')]
    public function onGet(): static
    {
        $this->body['read'] = $this->body['counter']['call'];

        return $this;
    }
}
