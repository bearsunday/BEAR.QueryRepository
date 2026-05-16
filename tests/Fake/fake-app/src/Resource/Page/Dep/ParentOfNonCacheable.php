<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ParentOfNonCacheable extends ResourceObject
{
    public $body = ['parent-of-non-cacheable' => 1];

    #[Embed(rel: 'child', src: '/dep/non-cacheable-child')]
    public function onGet()
    {
        return $this;
    }
}
