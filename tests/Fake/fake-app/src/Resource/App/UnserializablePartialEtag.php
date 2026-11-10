<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/** A partial ETag reads only the declared field, so the closure beside it is left to the body check */
#[Cacheable, HttpCache(maxAge: 60, etag: ['id'])]
class UnserializablePartialEtag extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['id' => 1, 'handler' => static fn () => null];

        return $this;
    }
}
