<?php

namespace FakeVendor\HelloWorld\Resource\Page\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** Embeds a #[Cacheable] child of another scheme */
#[Cacheable]
class CrossScheme extends ResourceObject
{
    public $body = [
        'cross' => 'hal',
    ];

    #[Embed(rel: 'branch', src: 'app://self/hal/branch')]
    public function onGet()
    {
        return $this;
    }
}
