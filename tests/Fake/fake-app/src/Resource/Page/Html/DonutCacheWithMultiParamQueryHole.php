<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page embedding a hole through #[Embed]'s own URI-template expansion with
 * two query parameters (issue #222 follow-up E2E coverage). `|raw` in the template sidesteps
 * a separate, out-of-scope Twig-autoescaping issue (default `{{ hole }}` HTML-escapes the
 * placeholder's `&` when 2+ params are present, reported separately, not fixed here).
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
