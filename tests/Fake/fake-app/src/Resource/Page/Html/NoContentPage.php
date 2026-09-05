<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * Answers 204 leaving the body null, the shape a resource with nothing to return has.
 */
#[CacheableResponse]
class NoContentPage extends ResourceObject
{
    public function onGet()
    {
        $this->code = Code::NO_CONTENT;

        return $this;
    }
}
