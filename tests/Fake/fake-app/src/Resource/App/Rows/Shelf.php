<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] resource embedding an uncached list */
#[Cacheable]
class Shelf extends ResourceObject
{
    #[Embed(rel: 'list', src: 'app://self/rows/plain?channelId=c1')]
    public function onGet(): static
    {
        $this->body['shelf'] = true;

        return $this;
    }

    public function onPut(): static
    {
        return $this;
    }
}
