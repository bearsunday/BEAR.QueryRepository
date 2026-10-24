<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page with two sibling holes: one that marks itself every request
 * (UnstorableValue) and one that is itself #[DonutCache] and never marks (PlainDonutHole).
 * ResourceDonut::refresh() re-fetching the marking hole without scoping that mark to its
 * own URI must not make the unrelated hole's own template write look refused too (issue #222).
 */
#[DonutCache]
class DonutCacheWithMarkingAndNestedDonutCacheHole extends ResourceObject
{
    #[Embed(rel: 'secret', src: 'app://self/unstorable-value')]
    #[Embed(rel: 'plain', src: 'page://self/html/plain-donut-hole')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
