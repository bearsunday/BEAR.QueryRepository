<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/**
 * Like App\MarksSiblingThenGenuinelyFails / Page\Html\DonutMarksSiblingThenGenuinelyFails, but
 * returns normally instead of throwing: a sibling (UnstorableValue) marks itself and has its
 * own write skipped first - reported pending, not suppressed (a plain get(), not an #[Embed]
 * hole, so isNested() stays false) - and THEN this page's own onGet() returns a normal body.
 * Paired with a bound RenderInterface that throws, this lets a genuine failure happen at STORE
 * time (the donut template's own render) rather than in onGet() itself, with a pending skip
 * already recorded.
 */
#[DonutCache]
class DonutMarksSiblingThenRendersNormally extends ResourceObject
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
