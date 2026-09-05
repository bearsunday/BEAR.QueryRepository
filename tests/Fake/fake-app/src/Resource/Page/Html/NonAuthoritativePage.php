<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * Answers 203, the code that used to be stored here and evicted by `#[Cacheable]`
 *
 * Below the old `>= 400` threshold and not a 200, so it is the response that tells the two
 * skip rules apart (issue #190).
 */
#[CacheableResponse]
class NonAuthoritativePage extends ResourceObject
{
    public function onGet()
    {
        $this->code = 203;
        $this->body = ['greeting' => 'transformed by a proxy'];

        return $this;
    }
}
