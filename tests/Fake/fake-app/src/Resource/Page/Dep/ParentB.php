<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ParentB extends ResourceObject
{
    public $body = ['parent-b' => 1];

    #[Embed(rel: 'child', src: '/dep/child-c')]
    public function onGet()
    {
        return $this;
    }
}
