<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * A #[CacheableResponse] page whose body holds a value serialize() refuses. MobileEtagSetter
 * serializes the body unconditionally, even though the view is already a rendered string
 * (issue #259) - EtagSetter does not reach this path, since it serializes the view first.
 */
#[CacheableResponse]
class UnserializableDonut extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body['handler'] = static fn () => null;

        return $this;
    }
}
