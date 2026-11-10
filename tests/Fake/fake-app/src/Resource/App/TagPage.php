<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class TagPage extends ResourceObject
{
    public static int $version = 1;

    public function onGet(string $id, int $page = 1): static
    {
        $this->body = ['v' => $id . ':' . $page . ':v' . self::$version];

        return $this;
    }
}
