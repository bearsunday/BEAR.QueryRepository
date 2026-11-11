<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Article extends ResourceObject
{
    /** @var array<int, string> */
    private static array $titles = [];

    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'title' => self::$titles[$id] ?? "Article {$id}"];

        return $this;
    }

    /** A #[Cacheable] resource's own write purges its URI with default parameters dropped, so this needs no #[Refresh]/#[Purge] of its own */
    public function onPatch(int $id, string $title): static
    {
        self::$titles[$id] = $title;

        return $this;
    }
}
