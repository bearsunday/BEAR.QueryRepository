<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] child that declares no Surrogate-Key of its own - only its own
 * interceptor's ETag ever reaches its headers, pinning the ETag side of material
 * classification (as opposed to every other fixture, which also carries a key).
 */
#[Cacheable]
class PlainCacheableChild extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['value' => 'plain-cacheable-child-value'];

        return $this;
    }
}
