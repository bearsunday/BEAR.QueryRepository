<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page with a pure hole over MutableComment - used to force two full
 * create()/render() cycles for the same page in one container (DonutRenderer#setView must
 * overwrite by placeholder, not append, or the second MISS replays the first MISS's view).
 */
#[DonutCache]
class DonutCacheWithMutableHole extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/mutable-comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
