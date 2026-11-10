<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page whose body holds a closure: the page stores its rendered view, not the body */
#[CacheableResponse]
class ClosureBodyDonut extends ResourceObject
{
    public static int $calls = 0;

    public function onGet(): static
    {
        self::$calls++;
        $this->body = ['handler' => static fn () => null, 'title' => 'closure-body'];

        return $this;
    }
}
