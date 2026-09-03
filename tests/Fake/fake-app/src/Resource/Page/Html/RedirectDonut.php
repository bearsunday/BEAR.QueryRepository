<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * The same 301 under #[DonutCache]: only the template is stored, so every later
 * request is served by a refresh rather than by a stored page view.
 */
#[DonutCache]
class RedirectDonut extends ResourceObject
{
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet()
    {
        $this->code = Code::MOVED_PERMANENTLY;
        $this->headers['Location'] = '/html/blog-posting?id=0';

        return $this;
    }
}
