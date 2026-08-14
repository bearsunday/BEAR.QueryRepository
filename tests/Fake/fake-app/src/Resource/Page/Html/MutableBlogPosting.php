<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[CacheableResponse]
class MutableBlogPosting extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/mutable-comment')]
    public function onGet(): static
    {
        $this->body += ['article' => '1'];
        $this->headers[Header::SURROGATE_KEY] = 'mutable-blog-posting-page';

        return $this;
    }
}
