<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * Stands in for a hole that fails mid-render: the failure must travel to the caller
 * uncaught, the same way a page with no cache attribute at all would fail.
 */
class ThrowingHole extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        throw new RuntimeException('the hole blew up mid-render');
    }
}
