<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** One template declared without a key, which reads the body as its row, and with the empty key, which reads the rows under '' */
#[Cacheable]
class EmptyKey extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={id}')]
    #[DependsOn(uri: 'app://self/rows/item?id={id}', key: '')]
    public function onGet(): static
    {
        $this->body = ['id' => 1, '' => [['id' => 2]]];

        return $this;
    }
}
