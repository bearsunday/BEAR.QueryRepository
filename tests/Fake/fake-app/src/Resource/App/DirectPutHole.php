<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;

/**
 * Embedded as a hole AFTER an unrelated marking hole on the same `#[DonutCache]` page
 * (DonutPageWithMarkingHoleCacheableSiblingAndDirectPut), so its OWN execution gets its OWN
 * hole scope (via `ResourceDonut::create()`'s DonutRequest wrapping, same as the marking
 * hole's), distinct from TOP_LEVEL and from the marking hole's own scope - isolating the
 * direct put()'s refusal to genuinely come from the any-owner isMarked() fallback
 * (isCurrentWriteFor() false), not from accidentally sharing a scope with the mark.
 */
#[Cacheable]
class DirectPutHole extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
    ) {
    }

    public function onGet(): static
    {
        $direct = new None();
        $direct->uri = new Uri('app://self/direct-put-after-unrelated-hole-mark');
        $direct->code = 200;
        $direct->body = ['value' => 'direct'];
        $this->queryRepository->put($direct);

        $this->body = ['value' => 'direct-put-hole'];

        return $this;
    }
}
