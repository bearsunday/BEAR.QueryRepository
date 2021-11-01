<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use Koriym\HttpConstants\CacheControl;
use Koriym\HttpConstants\RequestHeader;

class BlogPostingCache extends ResourceObject
{
    public $headers = [
        RequestHeader::CACHE_CONTROL => CacheControl::NO_STORE
    ];

    /**
     * @Embed(rel="comment", src="page://self/html/comment")
     * @CacheableResponse
     */
    #[CacheableResponse]
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet(int $id = 0)
    {
        $this->body['article'] = '1';

        return $this;
    }

    /**
     * @RefreshCache()
     */
    #[RefreshCache]
    public function onDelete(int $id = 0)
    {
        return $this;
    }
}