<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use Generator;

/** Rows under a key that a generator yields from two lists, so each row has the key 0 */
#[Cacheable]
class GeneratorRepeatedKeys extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}', key: 'articles')]
    public function onGet(): static
    {
        $this->body['articles'] = $this->rows();

        return $this;
    }

    /** @return Generator<int, array{id: int}> */
    private function rows(): Generator
    {
        yield from [['id' => 1]];
        yield from [['id' => 2]];
    }
}
