<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/** Declared private, yet hands the CDN a lifetime of its own */
#[Cacheable(expirySecond: 10), HttpCache(isPrivate: true)]
class CacheablePrivateWithCdnHeaders extends ResourceObject
{
    public static int $calls = 0;

    public function onGet(): static
    {
        self::$calls++;
        $this->headers[Header::CDN_CACHE_CONTROL] = 'max-age=600';
        $this->headers['surrogate-control'] = 'max-age=600';
        $this->body['article'] = '1';

        return $this;
    }
}
