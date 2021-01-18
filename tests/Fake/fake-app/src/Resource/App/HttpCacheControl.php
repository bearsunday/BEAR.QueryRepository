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
 * @Cacheable
 * @HttpCache(isPrivate=true, maxAge=0, sMaxAge=0, mustRevalidate=true, noStore=true, noCache=true)
 */
#[Cacheable]
#[HttpCache(isPrivate: true, maxAge: 0, sMaxAge: 0, mustRevalidate: true, noStore: true, noCache: true)]
class HttpCacheControl extends ResourceObject
{

    public function onGet() : ResourceObject
    {
        return $this;
    }
}
