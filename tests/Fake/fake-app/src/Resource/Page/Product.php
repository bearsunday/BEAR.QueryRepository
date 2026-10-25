<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * BeMart-shaped #234 fixture, `page://self/product`: a `#[DonutCache]` product page carrying
 * two donut holes, deliberately embedded in "order A" - the cart-form hole (marks, CSRF)
 * BEFORE the cacheable stock hole - the order that actually triggers #234 (the real app's own
 * stock-before-form order happens to warm the stock write before the mark fires, masking the
 * bug by accident; this fixture does not rely on that).
 */
#[DonutCache]
class Product extends ResourceObject
{
    #[Embed(rel: 'cartEntryForm', src: 'page://self/product/cart-entry-form{?productCode}')]
    #[Embed(rel: 'stock', src: 'app://self/product/stock{?productCode}')]
    public function onGet(string $productCode = 'sample-001'): static
    {
        $this->body['productCode'] = $productCode;

        return $this;
    }
}
