<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * An UNRELATED `#[Cacheable]` resource that never marks - the literal #234 repro: embedded as
 * a sibling of a marking hole, its own write must be stored regardless of the sibling's mark.
 */
#[Cacheable(expirySecond: 60)]
class InnocentCacheableSibling extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['value' => 'innocent'];

        return $this;
    }
}
