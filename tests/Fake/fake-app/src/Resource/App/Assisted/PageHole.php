<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page whose uncached reader is left to render as a hole */
#[CacheableResponse]
class PageHole extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/assisted/child')]
    public function onGet(): static
    {
        $this->body['page'] = true;

        return $this;
    }
}
