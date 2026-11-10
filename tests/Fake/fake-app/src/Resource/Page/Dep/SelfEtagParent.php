<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class SelfEtagParent extends ResourceObject
{
    public $body = ['self-etag-parent' => 1];

    #[Embed(rel: 'child', src: '/dep/child-c')]
    public function onGet()
    {
        $this->headers['ETag'] = '"app-etag"';

        return $this;
    }
}
