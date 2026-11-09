<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] resource whose body holds a closure once it has been written */
#[Cacheable]
class ClosureAfterWrite extends ResourceObject
{
    public static bool $written = false;

    public function onGet(): static
    {
        $this->body = ['handler' => self::$written ? static fn () => null : null];

        return $this;
    }

    public function onPut(): static
    {
        self::$written = true;

        return $this;
    }
}
