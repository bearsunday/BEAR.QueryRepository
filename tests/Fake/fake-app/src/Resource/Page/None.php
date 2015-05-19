<?php

namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\Resource\ResourceObject;

class None extends ResourceObject
{
    public function onGet()
    {
        $this['time'] = microtime(true);
    }
}
