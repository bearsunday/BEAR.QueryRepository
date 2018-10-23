<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(expirySecond=10)
 * @HttpCache(maxAge=5)
 */
class HttpCacheControlOverrideMaxAge extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        return $this;
    }
}
