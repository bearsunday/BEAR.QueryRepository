<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** page <- uncached aisle <- cached shelf <- uncached list, on a donut page */
#[CacheableResponse]
class PageAisleDonut extends ResourceObject
{
    #[Embed(rel: 'aisle', src: 'app://self/rows/aisle')]
    public function onGet(): static
    {
        $this->body['read'] = (string) $this->body['aisle'];

        return $this;
    }
}
