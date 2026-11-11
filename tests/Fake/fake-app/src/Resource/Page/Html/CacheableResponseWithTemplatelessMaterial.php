<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page whose onGet() reads a #[Cacheable] child that has no template */
#[CacheableResponse]
class CacheableResponseWithTemplatelessMaterial extends ResourceObject
{
    #[Embed(rel: 'child', src: 'page://self/dep/child-c')]
    public function onGet(): static
    {
        $this->body['value'] = $this['child']['child-c'];

        return $this;
    }
}
