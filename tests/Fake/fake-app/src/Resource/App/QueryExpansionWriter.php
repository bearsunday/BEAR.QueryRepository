<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class QueryExpansionWriter extends ResourceObject
{
    public static int $writes = 0;

    /** @param array<string, int> $params */
    #[Purge(uri: 'app://self/paged-category{?params*}')]
    public function onPost(array $params): static
    {
        return $this->write(['params' => $params]);
    }

    #[Purge(uri: 'app://self/paged-category?{+qs}')]
    public function onPut(string $qs): static
    {
        return $this->write(['qs' => $qs]);
    }

    /** @param list<int> $categoryIds */
    #[Purge(uri: 'app://self/paged-category?category_id={categoryIds*}')]
    public function onPatch(array $categoryIds): static
    {
        return $this->write(['categoryIds' => $categoryIds]);
    }

    /** @param array<string, mixed> $body */
    private function write(array $body): static
    {
        self::$writes++;
        PagedCategory::$version++;
        $this->body = $body;

        return $this;
    }
}
