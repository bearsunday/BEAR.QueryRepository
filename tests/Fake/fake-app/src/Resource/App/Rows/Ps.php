<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\QueryRepository\DonutRepositoryInterface;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

use function array_map;

/** A list that donut-caches itself with a putStatic() call inside onGet(), no cache attribute */
class Ps extends ResourceObject
{
    /** @var array<array-key, list<string>> */
    public static array $data = ['1' => ['1', '3'], '2' => ['7']];

    public function __construct(
        private readonly DonutRepositoryInterface $repository,
    ) {
    }

    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(string $articleId): static
    {
        $this->body = array_map(static fn (string $id): array => ['id' => $id], self::$data[$articleId] ?? []);
        $this->repository->putStatic($this);

        return $this;
    }
}
