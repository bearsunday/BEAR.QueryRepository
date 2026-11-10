<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

use function array_map;

/** A class-level #[Cacheable] (value) list of rows, each depending on its own app://self/rows/comment */
#[Cacheable]
class Ca extends ResourceObject
{
    public static int $runs = 0;

    /** @var array<array-key, list<string>> */
    public static array $data = ['1' => ['1', '3'], '2' => ['7']];

    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(string $article_id): static
    {
        self::$runs++;
        $this->body = array_map(static fn (string $id): array => ['id' => $id], self::$data[$article_id] ?? []);

        return $this;
    }

    public function onPost(string $article_id, string $id): static
    {
        self::$data[$article_id][] = $id;

        return $this;
    }
}
