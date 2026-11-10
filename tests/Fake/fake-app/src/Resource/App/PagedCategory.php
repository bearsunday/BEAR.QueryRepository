<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** The destination of the #[Purge]/#[Refresh] templates in PagedCategoryWriter and CacheablePagedCategoryWriter */
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
