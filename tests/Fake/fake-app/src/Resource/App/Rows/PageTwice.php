<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A page embedding one URI twice, each run returning another article */
#[Cacheable]
class PageTwice extends ResourceObject
{
    #[Embed(rel: 'first', src: 'app://self/rows/turn')]
    #[Embed(rel: 'second', src: 'app://self/rows/turn')]
    public function onGet(): static
    {
        return $this;
    }
}
