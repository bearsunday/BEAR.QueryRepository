<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

use function array_map;

/** A list with no cache declaration of its own: #[DependsOn] is read wherever it is materialized as a child */
class Plain extends ResourceObject
{
    /** @var array<array-key, list<string>> */
    public static array $data = ['1' => ['1', '3'], 'empty' => []];

    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(string $articleId): static
    {
        $this->body = array_map(static fn (string $id): array => ['id' => $id], self::$data[$articleId] ?? []);

        return $this;
    }
}
