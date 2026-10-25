<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page embedding a hole whose failure is controlled per test
 * (`FlakyHole::$failureCount`) - used to exercise `ResourceDonut::refresh()`'s fallback to a
 * fresh render on a HIT whose hole now fails.
 */
#[DonutCache]
class DonutCacheWithFlakyHole extends ResourceObject
{
    #[Embed(rel: 'flaky', src: 'app://self/flaky-hole')]
    public function onGet(): ResourceObject
    {
        return $this;
    }
}
