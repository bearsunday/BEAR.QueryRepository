<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** Declared private, yet hands the CDN a lifetime of its own */
#[CacheableResponse, HttpCache(isPrivate: true)]
class CacheableResponsePrivateWithCdnHeaders extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/comment')]
    public function onGet(): static
    {
        $this->headers[Header::CDN_CACHE_CONTROL] = 'max-age=600';
        $this->headers['surrogate-control'] = 'max-age=600';
        $this->body['article'] = '1';

        return $this;
    }
}
