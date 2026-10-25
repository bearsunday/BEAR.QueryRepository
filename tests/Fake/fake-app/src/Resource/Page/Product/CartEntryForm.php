<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Product;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\Resource\ResourceObject;

/**
 * BeMart-shaped #234 fixture: `page://self/product/cart-entry-form`, standing in for the real
 * app's CSRF-issuing add-to-cart form hole (`csrf_field()` calls
 * UncacheableMarkInterface::mark()).
 */
class CartEntryForm extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(string $productCode = 'sample-001'): static
    {
        $this->mark->mark('csrf-token-issued');
        $this->body = ['productCode' => $productCode, 'form' => 'csrf-token'];

        return $this;
    }
}
