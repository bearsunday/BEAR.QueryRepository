<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class TagIndex extends ResourceObject
{
    public static int $version = 1;

    public function onGet(int $page = 1): static
    {
        $this->body = ['v' => $page . ':v' . self::$version];

        return $this;
    }
}
