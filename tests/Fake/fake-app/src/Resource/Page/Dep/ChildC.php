<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ChildC extends ResourceObject
{
    public $body = ['child-c' => 1];

    public function onGet()
    {
        return $this;
    }
}
