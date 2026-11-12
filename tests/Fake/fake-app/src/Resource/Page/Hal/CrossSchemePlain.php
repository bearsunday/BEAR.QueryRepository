<?php

namespace FakeVendor\HelloWorld\Resource\Page\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** Embeds an uncached child of another scheme */
#[Cacheable]
class CrossSchemePlain extends ResourceObject
{
    public $body = [
        'cross' => 'hal',
    ];

    #[Embed(rel: 'branch', src: 'app://self/hal/plain-branch')]
    public function onGet()
    {
        return $this;
    }
}
