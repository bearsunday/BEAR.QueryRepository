<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * The donut-interceptor twin of App\MarksSiblingThenGenuinelyFails: a sibling
 * (UnstorableValue) marks itself and has its own write skipped - reported pending, not
 * suppressed (a plain get(), not an #[Embed] hole, so isNested() stays false) - and then, still
 * inside this page's own onGet(), something entirely unrelated fails for real.
 * AbstractDonutCacheInterceptor's own finally must let that failure travel and clear the
 * pending state rather than raise for it.
 */
#[DonutCache]
class DonutMarksSiblingThenGenuinelyFails extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
    ) {
    }

    public function onGet(): static
    {
        $this->resource->get('app://self/unstorable-value');

        throw new RuntimeException('a genuine failure unrelated to the pending skip');
    }
}
