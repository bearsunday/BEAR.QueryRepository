<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

#[HttpCache(maxAge: 60)]
class HttpCacheControlLowerCase extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        $this->headers['cache-control'] = 'public';

        return $this;
    }
}
