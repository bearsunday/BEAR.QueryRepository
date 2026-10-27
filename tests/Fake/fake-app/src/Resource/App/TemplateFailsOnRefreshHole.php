<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\ThrowingOnStringifyValue;

/**
 * A hole whose `onGet()` always succeeds; its own template render is what fails, controlled
 * by `ThrowingOnStringifyValue::$failCount` - exercises `ResourceDonut::refresh()`'s
 * `$ro->toString()` call specifically, not the earlier `$resource->get($uri)` re-fetch.
 */
class TemplateFailsOnRefreshHole extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = new ThrowingOnStringifyValue();

        return $this;
    }
}
