<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[CacheableResponse]
class ResponseOverUncachedMiddle extends ResourceObject
{
    #[Embed(rel: 'middle', src: 'page://self/html/uncached-middle')]
    public function onGet(): static
    {
        return $this;
    }
}
