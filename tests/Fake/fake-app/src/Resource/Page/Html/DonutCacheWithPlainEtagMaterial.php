<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose onGet() reads a plain #[Cacheable] child (ETag only, no
 * Surrogate-Key of its own) into another body key - pins the ETag branch of
 * classifyMaterials(), as opposed to every other material fixture, whose child also
 * carries a Surrogate-Key.
 */
#[DonutCache]
class DonutCacheWithPlainEtagMaterial extends ResourceObject
{
    #[Embed(rel: 'child', src: 'page://self/html/plain-cacheable-child')]
    public function onGet(): static
    {
        $this->body['article'] = '1';
        $this->body['childText'] = (string) $this->body['child'];

        return $this;
    }
}
