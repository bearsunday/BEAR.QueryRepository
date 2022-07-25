<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Code;
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
        $this->headers[Header::SURROGATE_KEY] = 'blog-posting-page';

        return $this;
    }

    public function onDelete(int $id = 0)
    {
        if ($id !== 0) {
            $this->code = Code::BAD_REQUEST;

            return $this;
        }

        return $this;
    }
}
