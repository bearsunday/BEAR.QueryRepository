<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page whose onGet() reads a query-variant child: a data dependency. */
#[CacheableResponse]
class VariantDonutParent extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/optional-param?id=1&page=2')]
    public function onGet(): static
    {
        $this->body['childTime'] = $this['child']['time'];
        $this->body['time'] = \microtime(true);

        return $this;
    }
}
