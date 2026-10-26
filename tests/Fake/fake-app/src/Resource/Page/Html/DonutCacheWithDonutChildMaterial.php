<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose onGet() reads an embedded #[DonutCache] child - a material
 * with no ETag, classified through its own Surrogate-Key instead.
 */
#[DonutCache]
class DonutCacheWithDonutChildMaterial extends ResourceObject
{
    #[Embed(rel: 'child', src: 'page://self/html/donut-material-child')]
    public function onGet(): static
    {
        $this->body['article'] = '1';
        $this->body['childText'] = (string) $this->body['child'];

        return $this;
    }
}
