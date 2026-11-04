<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class ParentOfKeyedChildren extends ResourceObject
{
    public $body = ['parent-of-keyed-children' => 1];

    #[Embed(rel: 'donut', src: '/dep/donut-cache-child')]
    #[Embed(rel: 'keyed', src: '/dep/keyed-non-cacheable-child')]
    public function onGet(): static
    {
        return $this;
    }
}
