<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page whose onGet() gives its embedded request the query */
#[Cacheable]
class AddsQueryParent extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/optional-param')]
    public function onGet(): static
    {
        $this->body['child']->addQuery(['id' => 1, 'page' => 2]);

        return $this;
    }
}
