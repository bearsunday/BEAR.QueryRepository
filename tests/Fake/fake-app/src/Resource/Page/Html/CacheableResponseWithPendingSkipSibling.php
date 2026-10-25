<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/**
 * Pairs with FakeDefectiveDonutRepository('write') to exercise AbstractDonutCacheInterceptor's
 * putRecorded(): a sibling (UnstorableValue) marks itself and has its own write skipped -
 * reported pending, not suppressed - BEFORE this page's own template write fails for an
 * unrelated, genuine reason inside putRecorded() itself (not inside onGet()). The pending skip
 * must never replace that failure.
 */
#[CacheableResponse]
class CacheableResponseWithPendingSkipSibling extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
    ) {
    }

    public function onGet(): static
    {
        $this->resource->get('app://self/unstorable-value');
        $this->body['article'] = '1';

        return $this;
    }
}
