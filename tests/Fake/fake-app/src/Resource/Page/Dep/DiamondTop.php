<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class DiamondTop extends ResourceObject
{
    public $body = ['diamond-top' => 1];

    #[Embed(rel: 'left', src: '/dep/diamond-left')]
    #[Embed(rel: 'right', src: '/dep/diamond-right')]
    public function onGet()
    {
        return $this;
    }
}
