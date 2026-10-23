<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page embedding a hole through #[Embed]'s own URI-template expansion with
 * two query parameters, rendered with the project's standard `{{ hole }}` (issue #232:
 * Twig's default autoescape turns the placeholder's `&` into `&amp;`, so neither the
 * first-request substitution nor the second-request `ResourceDonut::refresh()` re-fetch
 * matched it before the fix).
 */
#[DonutCache]
class DonutCacheWithMultiParamQueryHole extends ResourceObject
{
    #[Embed(rel: 'hole', src: 'app://self/multi-param-query-hole{?id,tag}')]
    public function onGet(string $id = '1', string $tag = '2') : ResourceObject
    {
        $this->body['article'] = '1';

        return $this;
    }
}
