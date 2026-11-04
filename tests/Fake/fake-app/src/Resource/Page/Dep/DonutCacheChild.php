<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/** Its own write sets a Surrogate-Key and never an ETag */
#[DonutCache]
class DonutCacheChild extends ResourceObject
{
    public $body = ['donut-cache-child' => 1];

    public function onGet(): static
    {
        return $this;
    }
}
