<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * A #[Cacheable(type: 'view')] resource whose body holds a closure
 *
 * The ETag is computed from the rendered view, so only the body check before the store catches it.
 */
#[Cacheable(type: 'view')]
class UnserializableView extends ResourceObject
{
    #[Override]
    public function toString(): string
    {
        return $this->view ??= 'rendered';
    }

    public function onGet(): static
    {
        $this->body = ['handler' => static fn () => null];

        return $this;
    }
}
