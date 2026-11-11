<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use BEAR\RepositoryModule\Annotation\DependsOn;

/** A row that points back to its parent */
#[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
final class Node
{
    public Node|null $parent = null;

    /** @var list<Node> */
    public array $children = [];

    public function __construct(
        public string $ulid,
    ) {
    }
}
