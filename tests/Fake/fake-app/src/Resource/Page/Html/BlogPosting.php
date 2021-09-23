<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use Koriym\HttpConstants\CacheControl;
use Koriym\HttpConstants\RequestHeader;

/**
 * @DonutCache
 */
#[DonutCache]
class BlogPosting extends ResourceObject
{
    public $headers = [
        RequestHeader::CACHE_CONTROL => CacheControl::NO_STORE  // no cache in the client (= max-age=0, must-revalidate)
//        'Cache-Control' => 'max-age=60, public'               // 60 sec cache in the client
//        'Cache-Control' => 'no-store, private'                // no cache in anywhere
    ];

    /**
     * @Embed(rel="comment", src="page://self/html/comment")
     */
    #[Embed(rel: "comment", src: "page://self/html/comment")]
    public function onGet(int $id = 0)
    {
        static $i = 0;

        $this->body += [
            'article' => '1',
        ];

        $this->headers['cnt'] = $i++;

        return $this;
    }

    public function onDelete(int $id = 0)
    {
        return $this;
    }
}