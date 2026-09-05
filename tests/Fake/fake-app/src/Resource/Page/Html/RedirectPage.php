<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * Answers 301, a status the donut save gate lets through: only >= 400 is skipped.
 *
 * The embedded child also makes the page reachable through the refresh path: purging the
 * child drops this page's stored view while its donut template survives.
 */
#[CacheableResponse]
class RedirectPage extends ResourceObject
{
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet()
    {
        $this->code = Code::MOVED_PERMANENTLY;
        $this->headers['Location'] = '/html/blog-posting?id=0';
        $this->body += [
            'url' => '/html/blog-posting?id=0'
        ];

        return $this;
    }
}
