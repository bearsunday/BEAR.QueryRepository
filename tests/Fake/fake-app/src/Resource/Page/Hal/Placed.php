<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Hal;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/**
 * Holds one child as an embedded request and one as a resource it placed itself
 */
#[Cacheable]
class Placed extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
    ) {
    }

    #[Embed(rel: 'child', src: '/hal/child')]
    public function onGet(): static
    {
        $this->body['placed'] = $this->resource->get('page://self/hal/child');

        return $this;
    }
}
