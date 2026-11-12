<?php

namespace FakeVendor\HelloWorld\Resource\Page\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** Embeds a child that embeds its own */
#[Cacheable]
class Nested extends ResourceObject
{
    public $body = [
        'nested' => 'hal',
    ];

    #[Embed(rel: 'parent', src: '/hal/parent-resource')]
    public function onGet()
    {
        return $this;
    }
}
