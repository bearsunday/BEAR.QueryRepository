<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] resource that sets its own Cache-Control to `private` directly in onGet(),
 * bypassing #[HttpCache] entirely: this still stops the server-side store, but keeps the
 * developer's own header instead of being overwritten.
 */
#[Cacheable(expirySecond: 60)]
class HandSetPrivateCacheControl extends ResourceObject
{
    public function onGet(): static
    {
        $this->headers[Header::CACHE_CONTROL] = 'private';
        $this->body = ['token' => 'value'];

        return $this;
    }
}
