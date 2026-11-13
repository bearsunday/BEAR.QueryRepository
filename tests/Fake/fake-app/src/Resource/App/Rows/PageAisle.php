<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** page <- uncached aisle <- cached shelf <- uncached list */
#[Cacheable]
class PageAisle extends ResourceObject
{
    #[Embed(rel: 'aisle', src: 'app://self/rows/aisle')]
    public function onGet(): static
    {
        $this->body['page'] = true;

        return $this;
    }
}
