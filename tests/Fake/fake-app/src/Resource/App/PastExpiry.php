<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiryAt: 'expiry_at')]
#[HttpCache(maxAge: 60, sMaxAge: 600)]
class PastExpiry extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['expiry_at' => '2000-01-01 00:00:00'];

        return $this;
    }
}
