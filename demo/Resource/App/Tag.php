<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Tag extends ResourceObject
{
    /** Bumped by every write, so a rebuilt entry shows a new version */
    public static int $version = 1;

    public function onGet(string $id): static
    {
        $this->body = ['id' => $id, 'version' => self::$version];

        return $this;
    }
}
