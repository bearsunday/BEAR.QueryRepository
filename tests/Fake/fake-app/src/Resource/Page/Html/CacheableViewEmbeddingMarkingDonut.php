<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * The #234 compound-leak scenario (rejected fix #1: rescoping doPut() to
 * isMarkedForCurrentScope() ONLY): a `#[Cacheable]` (type: 'view') page P embedding D
 * (DonutCacheWithUnstorableHole, itself `#[DonutCache]`), whose own hole H (UnstorableValue)
 * marks. H's mark is recorded under H's own owner, never P's - a scope-only check on P's own
 * write would never see it, storing P's rendered template with H's secret baked in verbatim.
 * wasMarkedSinceCurrentWrite() is what refuses P correctly: H marked DURING P's own open
 * write window (P's own toString() call, inside doPut()).
 */
#[Cacheable(type: 'view')]
class CacheableViewEmbeddingMarkingDonut extends ResourceObject
{
    #[Embed(rel: 'donut', src: 'page://self/html/donut-cache-with-unstorable-hole')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
