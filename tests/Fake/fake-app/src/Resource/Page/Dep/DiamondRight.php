<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class DiamondRight extends ResourceObject
{
    public $body = ['diamond-right' => 1];

    #[Embed(rel: 'bottom', src: '/dep/diamond-bottom')]
    public function onGet()
    {
        return $this;
    }
}
