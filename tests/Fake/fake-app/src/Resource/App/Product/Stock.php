<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Product;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * BeMart-shaped #234 fixture: `app://self/product/stock`, the real app's own `#[Cacheable]`
 * stock resource (reused as-is, no per-visitor data, never marks).
 */
#[Cacheable(expirySecond: 60)]
class Stock extends ResourceObject
{
    public function onGet(string $productCode = 'sample-001'): static
    {
        $this->body = ['productCode' => $productCode, 'stock' => 50, 'stockFind' => true];

        return $this;
    }
}
