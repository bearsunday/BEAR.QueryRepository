<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @CacheableResponse
 */
#[CacheableResponse]
class PageSurrogateKey extends ResourceObject
{
    /**
     * @Embed(rel="comment", src="page://self/html/comment")
     */
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet() : ResourceObject
    {
        $this->body = ['greeting' => 'hello'];
        $this->headers[Header::SURROGATE_KEY] = 'page-tag';

        return $this;
    }
}
