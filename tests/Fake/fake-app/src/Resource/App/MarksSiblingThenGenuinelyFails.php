<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * The #234 dev-gate regression this class stands in for: a sibling (UnstorableValue) marks
 * itself and has its own write skipped - reported pending by DevUnstorableGateModule's
 * observer, not suppressed (no hole scope is open for a plain get(), so isNested() is false) -
 * and THEN, still inside this resource's own execution, something entirely unrelated fails for
 * real. The pending skip must never replace that failure: CacheInterceptor's own finally must
 * let it travel and clear the pending state rather than raise for it.
 */
#[Cacheable]
class MarksSiblingThenGenuinelyFails extends ResourceObject
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
