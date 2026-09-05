<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * Answers 202, a 2xx a donut stores and #[Cacheable] skips.
 *
 * Unlike the 301 page this one renders its own template, so the embedded child is
 * composed into the donut and tags the stored view. Purging the child then drops the
 * view while the template survives, which is the way into the refresh path.
 */
#[CacheableResponse]
class AcceptedPage extends ResourceObject
{
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet()
    {
        $this->code = Code::ACCEPTED;

        return $this;
    }
}
