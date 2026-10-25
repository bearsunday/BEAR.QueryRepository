<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * Fable's scenario: like DonutCacheWithMarkingHoleAndCacheableSibling (the literal #234
 * repro - a marking hole, UnstorableValue, before an unrelated #[Cacheable] sibling,
 * InnocentCacheableSibling, that must still be stored), PLUS a direct (non-AOP)
 * `QueryRepository::put()` call made from App\DirectPutHole, embedded as a THIRD hole AFTER
 * the marking one. All three embeds stay LAZY here, resolved later by
 * `ResourceDonut::create()`'s own DonutRequest wrapping during this page's own write -
 * exactly like the marking hole and the AOP sibling, DirectPutHole's own execution gets its
 * OWN hole scope then too, distinct from TOP_LEVEL and from the marking hole's own scope -
 * isolating the direct put()'s refusal to genuinely come from the any-owner `isMarked()`
 * fallback (`UncacheableMarkScopeInterface::isCurrentWriteFor()` false, since the open frame,
 * if any, belongs to neither DirectPutHole's own write nor this page's), same as
 * 1.17.0/pre-#234 behaviour, not a regression. The AOP-cached sibling, reached through its OWN
 * `CacheInterceptor`-opened scope, stays correctly #234-scoped and is stored regardless.
 */
#[DonutCache]
class DonutPageWithMarkingHoleCacheableSiblingAndDirectPut extends ResourceObject
{
    #[Embed(rel: 'secret', src: 'app://self/unstorable-value')]
    #[Embed(rel: 'innocent', src: 'app://self/innocent-cacheable-sibling')]
    #[Embed(rel: 'directPutHole', src: 'app://self/direct-put-hole')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
