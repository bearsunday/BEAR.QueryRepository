<?php

namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
class Index extends ResourceObject
{
    public function onGet()
    {
        $this['time'] = microtime(true);

        return $this;
    }
}
