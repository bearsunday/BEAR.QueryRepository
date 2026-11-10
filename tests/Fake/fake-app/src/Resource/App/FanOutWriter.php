<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** Not #[Cacheable], so RefreshInterceptor handles the declarations */
class FanOutWriter extends ResourceObject
{
    /** @param string|array<string> $id */
    #[Purge(uri: 'app://self/tag-page{?id*}')]
    public function onPost(string|array $id): static
    {
        return $this->write(['id' => $id]);
    }

    /** @param list<string> $tagIds */
    #[Purge(uri: 'app://self/tag-page?id={tagIds*}')]
    public function onPut(array $tagIds): static
    {
        return $this->write(['tagIds' => $tagIds]);
    }

    /**
     * @param list<string> $id
     * @param list<int>    $page
     */
    #[Purge(uri: 'app://self/tag-page{?id*,page*}')]
    public function onPatch(array $id, array $page): static
    {
        return $this->write(['id' => $id, 'page' => $page]);
    }

    /** @param list<string> $id */
    #[Purge(uri: 'app://self/tag-page{?id}')]
    public function onDelete(array $id): static
    {
        return $this->write(['id' => $id]);
    }

    /** @param array<string, mixed> $body */
    private function write(array $body): static
    {
        TagPage::$version++;
        $this->body = $body;

        return $this;
    }
}
