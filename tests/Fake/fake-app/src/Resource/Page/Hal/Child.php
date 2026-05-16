<?php

namespace FakeVendor\HelloWorld\Resource\Page\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Child extends ResourceObject
{
    public $body = [
        'child' => 'hal',
    ];

    public function onGet()
    {
        return $this;
    }
}
