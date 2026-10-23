<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose hole is itself #[DonutCache] (RenderTimeMark): the hole runs its
 * own write while still nested inside this page's enterHole() scope, so its own render-time
 * mark must still refuse only its OWN template, not leak into the shared cache for a later,
 * different visitor who requests the hole's URI directly (issue #222).
 */
#[DonutCache]
class DonutCacheWithNestedDonutCacheHole extends ResourceObject
{
    #[Embed(rel: 'nested', src: 'page://self/html/render-time-mark')]
    public function onGet() : ResourceObject
    {
        $this->body['article'] = '1';

        return $this;
    }
}
