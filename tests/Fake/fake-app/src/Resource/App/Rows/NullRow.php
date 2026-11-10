<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list with one row whose dependency value is null: that row names no dependency and is skipped, not an error */
#[Cacheable]
class NullRow extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(): static
    {
        $this->body = [['id' => '1'], ['id' => null], ['id' => '3']];

        return $this;
    }
}
