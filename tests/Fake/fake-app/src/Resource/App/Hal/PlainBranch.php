<?php

namespace FakeVendor\HelloWorld\Resource\App\Hal;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** An app resource with no cache declaration that embeds its own child */
class PlainBranch extends ResourceObject
{
    public $body = [
        'branch' => 'hal',
    ];

    #[Embed(rel: 'leaf', src: 'app://self/hal/leaf')]
    public function onGet()
    {
        return $this;
    }
}
