<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page showing a #[CacheableResponse] part that embeds a resource of its own */
#[Cacheable]
class CacheableOverResponsePart extends ResourceObject
{
    #[Embed(rel: 'part', src: 'page://self/html/response-part')]
    public function onGet(): static
    {
        return $this;
    }
}
