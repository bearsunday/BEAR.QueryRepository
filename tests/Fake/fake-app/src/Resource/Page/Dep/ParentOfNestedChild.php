<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ParentOfNestedChild extends ResourceObject
{
    #[Embed(rel: 'child', src: '/dep/counting-child')]
    public function onGet()
    {
        $this->body['nested'] = ['child' => $this->body['child']];
        unset($this->body['child']);

        return $this;
    }
}
