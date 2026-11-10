<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** Leaves its arguments out of the body, so a declaration reaches them only through the request */
class ArgumentPurgeWriter extends ResourceObject
{
    #[Purge(uri: 'app://self/tag-page{?id}')]
    public function onPost(string $id): static
    {
        unset($id);
        TagPage::$version++;

        return $this;
    }

    #[Purge(uri: 'app://self/tag-page{?id,page}')]
    public function onPut(string $id, int $page = 1): static
    {
        unset($id, $page);
        TagPage::$version++;

        return $this;
    }

    #[Purge(uri: 'app://self/paged-category{?category_id,page}')]
    public function onPatch(int $categoryId, int $page): static
    {
        unset($categoryId, $page);
        PagedCategory::$version++;

        return $this;
    }
}
