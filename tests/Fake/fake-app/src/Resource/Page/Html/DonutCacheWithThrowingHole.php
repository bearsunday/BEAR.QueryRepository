<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose embedded hole always throws mid-render - the template composed
 * by `(string) $ro` in `ResourceDonut::create()` must never be stored: BEAR.Resource's
 * `ResourceObject::__toString()` swallows that Throwable into an empty string, and storing it
 * would serve an empty page to every later visitor (since 1.17.0).
 */
#[DonutCache]
class DonutCacheWithThrowingHole extends ResourceObject
{
    #[Embed(rel: 'secret', src: 'app://self/throwing-hole')]
    public function onGet(): ResourceObject
    {
        return $this;
    }
}
