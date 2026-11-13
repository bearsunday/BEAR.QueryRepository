<?php

namespace FakeVendor\HelloWorld\Resource\App\Hal;

use BEAR\Resource\ResourceObject;

/** An embedded resource with no cache declaration */
class Leaf extends ResourceObject
{
    public $body = [
        'leaf' => 'hal',
    ];

    public function onGet()
    {
        return $this;
    }
}
