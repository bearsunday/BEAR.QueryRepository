<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use Generator;

/** Rows under a key that a generator yields, which can be read only once */
#[Cacheable]
class GeneratorRows extends ResourceObject
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
        yield ['id' => 1];
        yield ['id' => 2];
    }
}
