<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A class-level #[CacheableResponse] page that runs its mid without rendering it, before the mid's list has run */
#[CacheableResponse]
class PageTouchLazyMid extends ResourceObject
{
    #[Embed(rel: 'list', src: 'app://self/rows/lazy-mid?channelId=c1')]
    public function onGet(): static
    {
        $this->body['code'] = $this->body['list']->code;

        return $this;
    }
}
