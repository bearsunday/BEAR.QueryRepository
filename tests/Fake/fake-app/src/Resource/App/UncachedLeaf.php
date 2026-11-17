<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

/** An uncached resource whose content a test changes */
class UncachedLeaf extends ResourceObject
{
    public static string $version = 'v1';

    public function onGet(): static
    {
        $this->body = ['version' => self::$version];

        return $this;
    }
}
