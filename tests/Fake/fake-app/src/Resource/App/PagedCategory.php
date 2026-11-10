<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class PagedCategory extends ResourceObject
{
    public static int $version = 1;

    public function onGet(int $categoryId, int $page, int $perPage = 20): static
    {
        $this->body = ['v' => $categoryId . ':' . $page . ':' . $perPage . ':v' . self::$version];

        return $this;
    }
}
