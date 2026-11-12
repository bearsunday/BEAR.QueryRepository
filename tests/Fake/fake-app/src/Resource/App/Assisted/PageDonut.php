<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A method-level #[CacheableResponse] page that reads its uncached reader */
class PageDonut extends ResourceObject
{
    #[CacheableResponse]
    #[Embed(rel: 'child', src: 'app://self/assisted/child')]
    public function onGet(): static
    {
        $this->body['read'] = (string) $this->body['child'];

        return $this;
    }
}
