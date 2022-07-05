<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable]
class Comment extends ResourceObject
{
    /**
     * @Embed(rel="like", src="page://self/html/like")
     */
    #[Embed(rel: "like", src: "page://self/html/like")]
    public function onGet()
    {
        $this->body = [
            'comment' => 'comment01'
        ];
        $this->headers[Header::SURROGATE_KEY] = 'comment01';

        return $this;
    }

    public function onDelete()
    {
        $this->code = Code::BAD_REQUEST;

        return $this;
    }
}
