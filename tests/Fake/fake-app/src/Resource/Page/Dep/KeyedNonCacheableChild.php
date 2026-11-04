<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\QueryRepository\Header;
use BEAR\Resource\ResourceObject;

/** Not cached, but names the data it serves with a Surrogate-Key of its own */
class KeyedNonCacheableChild extends ResourceObject
{
    public $body = ['keyed-non-cacheable-child' => 1];

    public function onGet(): static
    {
        $this->headers[Header::SURROGATE_KEY] = 'keyed-non-cacheable-child-data';

        return $this;
    }
}
