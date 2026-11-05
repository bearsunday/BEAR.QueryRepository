<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page whose child is a query variant of a resource with a default-valued parameter. */
#[Cacheable]
class VariantParent extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/optional-param?id=1&page=2')]
    public function onGet(): static
    {
        $this->body['time'] = \microtime(true);

        return $this;
    }
}
