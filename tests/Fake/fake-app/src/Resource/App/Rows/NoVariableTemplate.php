<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** A template with no variable at all: #[DependsOn] must refuse it */
#[Cacheable]
class NoVariableTemplate extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment')]
    public function onGet(): static
    {
        $this->body = [['id' => '1']];

        return $this;
    }
}
