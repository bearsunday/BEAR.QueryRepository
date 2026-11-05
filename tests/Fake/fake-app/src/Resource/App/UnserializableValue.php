<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/**
 * Stands in for a #[Cacheable] resource whose body holds a value PHP cannot serialize - a
 * closure here, a PDO connection or an HTTP client in production (issue #259)
 */
#[Cacheable(expirySecond: 60), HttpCache(maxAge: 60, sMaxAge: 600)]
class UnserializableValue extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['handler' => static fn () => null];

        return $this;
    }
}
