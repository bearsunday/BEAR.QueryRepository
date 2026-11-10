<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/** Not #[Cacheable], so RefreshInterceptor handles the declarations */
class PagedCategoryWriter extends ResourceObject
{
    public static int $writes = 0;

    #[Purge(uri: 'app://self/paged-category{?category_id}')]
    public function onPatch(int $categoryId): static
    {
        return $this->write(['category_id' => $categoryId]);
    }

    #[Refresh(uri: 'app://self/paged-category{?category_id}')]
    public function onPut(int $categoryId): static
    {
        return $this->write(['category_id' => $categoryId]);
    }

    #[Purge(uri: 'app://self/paged-category{?category_id,page}')]
    public function onPost(int $categoryId, int $page): static
    {
        return $this->write(['category_id' => $categoryId, 'page' => $page]);
    }

    #[Refresh(uri: 'app://self/paged-category{?categoryId,page}')]
    public function onDelete(int $categoryId, int $page): static
    {
        return $this->write(['categoryId' => $categoryId, 'page' => $page]);
    }

    /** @param array<string, int> $body */
    private function write(array $body): static
    {
        self::$writes++;
        PagedCategory::$version++;
        $this->body = $body;

        return $this;
    }
}
