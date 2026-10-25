<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\Resource\ResourceObject;

/**
 * A page with NO cache attribute at all - the actual baseline a `#[DonutCache]` render
 * failure is compared against, not a `#[Cacheable]` page (a different interceptor).
 */
class PlainNoCacheAttribute extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = 'plain';

        return $this;
    }
}
