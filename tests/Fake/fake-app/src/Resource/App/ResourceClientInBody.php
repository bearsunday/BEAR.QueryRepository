<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] resource whose body holds the resource client, as a URL helper that keeps it would (#267)
 *
 * The client keeps the last request it made, and a request refuses to serialize with a LogicException.
 */
#[Cacheable]
class ResourceClientInBody extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
    ) {
    }

    public function onGet(): static
    {
        $this->resource->get('app://self/user', ['id' => 1]);
        $this->body = ['client' => $this->resource];

        return $this;
    }
}
