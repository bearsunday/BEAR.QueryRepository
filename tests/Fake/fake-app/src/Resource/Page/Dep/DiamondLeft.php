<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class DiamondLeft extends ResourceObject
{
    public $body = ['diamond-left' => 1];

    #[Embed(rel: 'bottom', src: '/dep/diamond-bottom')]
    public function onGet()
    {
        return $this;
    }
}
