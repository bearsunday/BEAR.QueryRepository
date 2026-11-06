<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * A #[Cacheable(type: 'view')] resource whose body holds a closure (issue #261)
 *
 * The ETag is computed from the rendered view, so it succeeds; the entry stores the body too,
 * and the pool's marshaller refuses it.
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
