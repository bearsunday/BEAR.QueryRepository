<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\QueryRepository\CacheDependencyInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

#[Cacheable(type: 'view')]
class DependingInOnGetView extends ResourceObject
{
    public $body = ['depending-in-on-get' => 1];

    public function __construct(
        private readonly ResourceInterface $resource,
        private readonly CacheDependencyInterface $cacheDependency,
    ) {
    }

    public function onGet()
    {
        $this->cacheDependency->depends($this, $this->resource->get('page://self/dep/child-c'));

        return $this;
    }
}
