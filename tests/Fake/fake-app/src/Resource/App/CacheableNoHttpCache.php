<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;

/**
 * #[NoHttpCache] declares its own Cache-Control: private, no-store, no-cache, must-revalidate -
 * the server-side #[Cacheable] store must keep working regardless (issue #222 regression guard).
 */
#[Cacheable(expirySecond: 10), NoHttpCache]
class CacheableNoHttpCache extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        $this->body = ['token' => 'not-private'];

        return $this;
    }
}
