<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] parent whose embedded child throws */
#[Cacheable]
class ParentOfThrowing extends ResourceObject
{
    public $body = ['parent-of-throwing' => 1];

    #[Embed(rel: 'child', src: 'app://self/throwing-hole')]
    public function onGet()
    {
        return $this;
    }
}
