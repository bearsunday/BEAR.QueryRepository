<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

use function array_map;

/** A method-level #[CacheableResponse] (donut view-only) list of rows */
class Mr extends ResourceObject
{
    /** @var array<array-key, list<string>> */
    public static array $data = ['1' => ['1', '3'], '2' => ['7']];

    #[CacheableResponse]
    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(string $articleId): static
    {
        $this->body = array_map(static fn (string $id): array => ['id' => $id], self::$data[$articleId] ?? []);

        return $this;
    }
}
