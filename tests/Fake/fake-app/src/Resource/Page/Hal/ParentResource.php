<?php

namespace FakeVendor\HelloWorld\Resource\Page\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ParentResource extends ResourceObject
{
    public $body = [
        'parent' => 'hal',
    ];

    #[Embed(rel: 'child', src: '/hal/child')]
    public function onGet()
    {
        return $this;
    }
}
