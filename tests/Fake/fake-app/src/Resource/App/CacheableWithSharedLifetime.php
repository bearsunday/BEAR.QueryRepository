<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] without a lifetime that gives shared caches one */
#[Cacheable, HttpCache(sMaxAge: 600)]
class CacheableWithSharedLifetime extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['id' => 1];

        return $this;
    }
}
