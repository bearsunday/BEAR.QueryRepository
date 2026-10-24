<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * A plain #[DonutCache] hole that never marks itself - the "unrelated sibling" whose own
 * template re-save must not be caught by a DIFFERENT, marking sibling hole during a page
 * refresh (issue #222: ResourceDonut::refresh() hole-scope attribution).
 */
#[DonutCache]
class PlainDonutHole extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = 'plain';

        return $this;
    }
}
