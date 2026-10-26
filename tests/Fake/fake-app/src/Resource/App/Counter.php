<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * Counts its onGet() runs; not cacheable, so every explicit get() and every embedded read runs it
 */
class Counter extends ResourceObject
{
    public static int $calls = 0;

    public function onGet(string|null $tag = null): static
    {
        self::$calls++;
        $this->body = [
            'call' => self::$calls,
            'tag' => $tag,
        ];

        return $this;
    }
}
