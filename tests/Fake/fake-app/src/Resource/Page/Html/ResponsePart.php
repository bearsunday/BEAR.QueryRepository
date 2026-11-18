<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] part over a cached resource */
#[CacheableResponse]
class ResponsePart extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/versioned-leaf')]
    public function onGet(): static
    {
        return $this;
    }
}
