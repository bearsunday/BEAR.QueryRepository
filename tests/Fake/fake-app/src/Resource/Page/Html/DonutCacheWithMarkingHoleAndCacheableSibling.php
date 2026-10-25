<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * The literal #234 repro: a `#[DonutCache]` page embedding a hole that issues a CSRF token
 * (UnstorableValue, marks every request) BEFORE an entirely unrelated `#[Cacheable]` sibling
 * (InnocentCacheableSibling, never marks). `QueryRepository::doPut()`'s own-write-scoped
 * predicate (#234) must store the sibling's own entry regardless of the marking hole's mark.
 */
#[DonutCache]
class DonutCacheWithMarkingHoleAndCacheableSibling extends ResourceObject
{
    #[Embed(rel: 'secret', src: 'app://self/unstorable-value')]
    #[Embed(rel: 'innocent', src: 'app://self/innocent-cacheable-sibling')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
