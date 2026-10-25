<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * Same shape as CounterDonut, but `#[CacheableResponse]` sits on the method (BlogPostingCache
 * is this shape too) rather than the class - proves DonutCacheModule::installAopMethodModule()'s
 * own DonutCacheInterceptor binding (not just installAopClassModule()'s) reads an
 * already-read embed from the same single execution too.
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
