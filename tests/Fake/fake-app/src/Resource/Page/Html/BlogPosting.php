<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @CacheableResponse
 */
#[CacheableResponse]
class BlogPosting extends ResourceObject
{
    /** @var array */
    public $body = [
        'article' => 1
    ];

    /**
     * @Embed(rel="comment", src="page://self/html/comment")
     */
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet(int $id = 0)
    {
        return $this;
    }

    public function onDelete(int $id = 0)
    {
        return $this;
    }
}