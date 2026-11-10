<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A list that names its own Surrogate-Key by hand as well as declaring #[DependsOn] */
#[Cacheable]
class HandKey extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment{?id}')]
    public function onGet(): static
    {
        $this->headers[Header::SURROGATE_KEY] = 'hand-key';
        $this->body = [['id' => '5']];

        return $this;
    }
}
