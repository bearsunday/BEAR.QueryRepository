<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[CacheableResponse]
#[HttpCache(sMaxAge: 600)]
class ProbeCacheableResponseToo extends ResourceObject
{
    #[Embed(rel: 'cached', src: 'app://self/code')]
    #[Embed(rel: 'uncached', src: 'app://self/no-http-cache-control')]
    public function onGet(): static
    {
        $this->body['x'] = 1;

        return $this;
    }
}
