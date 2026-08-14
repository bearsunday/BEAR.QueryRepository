<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** type: "view" stores body and view in one entry, so both must come from the same child run */
#[Cacheable(type: 'view')]
class ParentOfCounting extends ResourceObject
{
    public $body = ['parent-of-counting' => 1];

    #[Embed(rel: 'child', src: '/dep/counting-child')]
    public function onGet()
    {
        return $this;
    }
}
