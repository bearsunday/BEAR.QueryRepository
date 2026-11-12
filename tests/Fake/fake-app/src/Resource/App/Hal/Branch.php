<?php

namespace FakeVendor\HelloWorld\Resource\App\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] app resource that embeds its own child */
#[Cacheable]
class Branch extends ResourceObject
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
