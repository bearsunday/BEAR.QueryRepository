<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * Embeds a child with no ETag before one that has it: the dependency walk must skip the
 * first and still reach the second, or purging the second leaves this page stale.
 */
#[Cacheable]
class ParentOfMixed extends ResourceObject
{
    public $body = ['parent-of-mixed' => 1];

    #[Embed(rel: 'uncacheable', src: '/dep/non-cacheable-child')]
    #[Embed(rel: 'cacheable', src: '/dep/child-c')]
    public function onGet()
    {
        return $this;
    }
}
