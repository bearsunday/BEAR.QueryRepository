<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** Its body holds a closure, which cannot be stored */
#[Cacheable]
class Report extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['format' => static fn (string $value): string => $value];

        return $this;
    }
}
