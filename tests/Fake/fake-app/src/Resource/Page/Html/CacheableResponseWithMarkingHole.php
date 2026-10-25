<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A `#[CacheableResponse]` page (not `#[DonutCache]`) whose embedded hole marks itself
 * (UnstorableValue). `DonutRepository::doPutStatic()` stores the WHOLE rendered content, any-
 * owner `isMarked()` (unchanged, #234) - so the hole's mark DOES refuse this page's own write,
 * and by the time that refusal is checked the hole's own enterHole()/leaveHole() scope has
 * already closed: `DevUnstorableGateModule`'s observer must report this (not suppressed),
 * unlike a `#[DonutCache]` page's own template write, which a hole's mark never blocks.
 */
#[CacheableResponse]
class CacheableResponseWithMarkingHole extends ResourceObject
{
    #[Embed(rel: 'secret', src: 'app://self/unstorable-value')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
