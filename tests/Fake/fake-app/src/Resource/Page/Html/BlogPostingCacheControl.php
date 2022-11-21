<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\DonutRepositoryInterface;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use Koriym\HttpConstants\CacheControl;
use Koriym\HttpConstants\RequestHeader;

/**
 * @CacheableResponse
 */
#[CacheableResponse]
class BlogPostingCacheControl extends ResourceObject
{
    public $headers = [
        RequestHeader::CACHE_CONTROL => CacheControl::NO_STORE
    ];

    public function __construct(
        private DonutRepositoryInterface $repository
    ){
    }

    /**
     * @Embed(rel="comment", src="page://self/html/comment")
     */
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet()
    {
        $this->body['article'] = '1';

        $this->repository->putStatic($this, 100, 10);

        return $this;
    }
}
