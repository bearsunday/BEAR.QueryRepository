<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use Generator;

/** A body that is a generator of rows, which can be read only once, under list keys or under keys of its own */
#[Cacheable]
class GeneratorBody extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    #[DependsOn(uri: 'app://self/rows/shelf')]
    public function onGet(bool $keyed = false): static
    {
        $this->body = $this->rows($keyed);

        return $this;
    }

    /** @return Generator<array-key, array{id: int}> */
    private function rows(bool $keyed): Generator
    {
        yield $keyed ? 'a' : 0 => ['id' => 1];
        yield $keyed ? 'b' : 1 => ['id' => 2];
    }
}
