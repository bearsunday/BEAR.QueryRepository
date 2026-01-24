<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ParentA extends ResourceObject
{
    public $body = ['parent-a' => 1];

    #[Embed(rel: 'child', src: '/dep/child-c')]
    public function onGet()
    {
        return $this;
    }
}
