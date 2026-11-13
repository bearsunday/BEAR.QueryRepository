<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A cached resource whose content a test changes before purging it */
#[Cacheable]
class VersionedLeaf extends ResourceObject
{
    public static string $version = 'v1';

    public function onGet(): static
    {
        $this->body = ['version' => self::$version];

        return $this;
    }
}
