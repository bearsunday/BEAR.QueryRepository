<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\Resource\ResourceObject;

class NonCacheableChild extends ResourceObject
{
    public $body = ['non-cacheable-child' => 1];

    public function onGet()
    {
        return $this;
    }
}
