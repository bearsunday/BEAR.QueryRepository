<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page showing a #[CacheableResponse] part that embeds an uncached resource */
#[Cacheable]
class CacheableOverResponsePartOverUncachedLeaf extends ResourceObject
{
    #[Embed(rel: 'part', src: 'page://self/html/response-part-over-uncached-leaf')]
    public function onGet(): static
    {
        return $this;
    }
}
