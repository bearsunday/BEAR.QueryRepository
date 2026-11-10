<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/** Not #[Cacheable], so RefreshInterceptor handles the declarations */
class FanOutRefreshWriter extends ResourceObject
{
    /** @param list<string> $id */
    #[Refresh(uri: 'app://self/tag-page{?id*}')]
    public function onPost(array $id): static
    {
        TagPage::$version++;
        $this->body = ['id' => $id];

        return $this;
    }

    /** @param list<int> $page */
    #[Refresh(uri: 'app://self/tag-index{?page*}')]
    public function onPut(array $page): static
    {
        TagIndex::$version++;
        $this->body = ['page' => $page];

        return $this;
    }
}
