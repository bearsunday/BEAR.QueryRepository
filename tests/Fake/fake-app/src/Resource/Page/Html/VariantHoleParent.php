<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page that only renders a query-variant child: a hole. */
#[CacheableResponse]
class VariantHoleParent extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/optional-param?id=1&page=2')]
    public function onGet(): static
    {
        $this->body['time'] = \microtime(true);

        return $this;
    }
}
