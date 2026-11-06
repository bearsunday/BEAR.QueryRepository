<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/**
 * Stands in for a #[Cacheable] resource whose body holds a value PHP cannot serialize - a
 * closure here, a PDO connection or an HTTP client in production (issue #259)
 *
 * Hand-sets Surrogate-Key and CDN-Cache-Control the way an embedded-dependency write would,
 * so a test asserting they are stripped on skip proves the strip, not their mere absence.
 */
#[Cacheable(expirySecond: 60), HttpCache(maxAge: 60, sMaxAge: 600)]
class UnserializableValue extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->headers[Header::SURROGATE_KEY] = 'unserializable-value-tag';
        $this->headers[Header::CDN_CACHE_CONTROL] = 'max-age=600';
        $this->body = ['handler' => static fn () => null];

        return $this;
    }
}
