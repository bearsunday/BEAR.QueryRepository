<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

class InheritedHttpCacheBase extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['ok' => true];

        return $this;
    }
}
