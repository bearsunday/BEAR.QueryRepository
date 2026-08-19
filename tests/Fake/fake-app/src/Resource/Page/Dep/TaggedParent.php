<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class TaggedParent extends ResourceObject
{
    public $body = ['tagged-parent' => 1];

    #[Embed(rel: 'bottom', src: '/dep/diamond-bottom')]
    public function onGet()
    {
        $this->headers[Header::SURROGATE_KEY] = 'shared-corpus';

        return $this;
    }
}
