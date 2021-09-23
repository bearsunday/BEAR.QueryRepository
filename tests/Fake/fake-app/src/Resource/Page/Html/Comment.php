<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable(type: 'view')]
class Comment extends ResourceObject
{
    #[Embed(rel: "like", src: "page://self/html/like")]
    public function onGet()
    {
        $this->body = [
            'comment' => 'comment01'
        ];

        return $this;
    }
}