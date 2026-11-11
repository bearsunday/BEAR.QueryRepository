<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Node;

/** Rows that point at each other */
#[Cacheable]
class Cyclic extends ResourceObject
{
    public function onGet(): static
    {
        $parent = new Node('a1');
        $child = new Node('a3');
        $child->parent = $parent;
        $parent->children[] = $child;
        $this->body = ['tree' => $parent];

        return $this;
    }
}
