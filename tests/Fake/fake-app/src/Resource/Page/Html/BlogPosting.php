<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use Koriym\HttpConstants\CacheControl;
use Koriym\HttpConstants\ResponseHeader;

/**
 * @CacheableResponse
 */
#[CacheableResponse]
class BlogPosting extends ResourceObject
{
    /**
     * @Embed(rel="comment", src="page://self/html/comment")
     */
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet(int $id = 0)
    {
        $this->body += [
            'article' => '1'
        ];

        return $this;
    }

    public function onDelete(int $id = 0)
    {
        return $this;
    }
}