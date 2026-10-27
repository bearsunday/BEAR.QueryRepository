<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page embedding a hole whose own template render fails per test
 * (`ThrowingOnStringifyValue::$failCount`) - used to exercise `ResourceDonut::refresh()`'s
 * fallback to a fresh render when a hole's template, not its onGet(), fails on a HIT.
 */
#[DonutCache]
class DonutCacheWithTemplateFailsOnRefreshHole extends ResourceObject
{
    #[Embed(rel: 'flaky', src: 'app://self/template-fails-on-refresh-hole')]
    public function onGet(): ResourceObject
    {
        return $this;
    }
}
