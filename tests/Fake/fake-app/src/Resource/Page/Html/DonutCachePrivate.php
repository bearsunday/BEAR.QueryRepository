<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[DonutCache, HttpCache(isPrivate: true)]
class DonutCachePrivate extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
