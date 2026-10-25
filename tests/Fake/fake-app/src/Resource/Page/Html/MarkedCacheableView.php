<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A top-level `#[Cacheable]` (type: 'view') resource that marks ITSELF, with no hole to
 * isolate the mark in - the actual #222 misuse `DevUnstorableGateModule`'s observer must
 * report (not suppressed: `UncacheableMarkScopeInterface::isNested()` is false here, unlike a hole
 * legitimately refusing its own write).
 */
#[Cacheable(type: 'view')]
class MarkedCacheableView extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(): static
    {
        $this->mark->mark('csrf-token-issued');
        $this->body['token'] = 'secret';

        return $this;
    }
}
