<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * Proves CacheInterceptor's own setWriteOwner() re-bind is load-bearing: onGet() returns a
 * FRESH ResourceObject instance, not `$this` - exactly the case enterWrite() alone cannot
 * handle, since it opens the write-scope frame BEFORE proceed() runs (a mark during proceed()
 * must land inside the window), tagged with the PRE-proceed instance. Without the re-bind,
 * isCurrentWriteFor() would never match the instance actually passed to put() (proceed()'s
 * return value), silently falling back to any-owner and over-refusing this resource past an
 * unrelated mark that #234's own scoping would otherwise correctly ignore.
 */
#[Cacheable(expirySecond: 60)]
class CacheableReturnsAFreshInstanceFromOnGet extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $fresh = new self();
        $fresh->uri = $this->uri;
        $fresh->code = 200;
        $fresh->body = ['value' => 'fresh-instance'];

        return $fresh;
    }
}
