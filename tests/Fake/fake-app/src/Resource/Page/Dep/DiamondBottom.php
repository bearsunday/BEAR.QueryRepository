<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class DiamondBottom extends ResourceObject
{
    public $body = ['diamond-bottom' => 1];

    public function onGet()
    {
        return $this;
    }
}
