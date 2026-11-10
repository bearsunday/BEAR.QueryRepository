<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page embedding a declared list that has no rows for this call */
#[Cacheable]
class PageEmptyRows extends ResourceObject
{
    #[Embed(rel: 'rows', src: 'app://self/rows/plain?article_id=empty')]
    public function onGet(): static
    {
        $this->body['article'] = 'empty';

        return $this;
    }
}
