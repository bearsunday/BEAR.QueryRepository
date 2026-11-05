<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use DOMDocument;

/** A #[Cacheable] body holding a DOM node, whose serialize() refusal carries a trailing clause */
#[Cacheable(expirySecond: 60)]
class UnserializableDomValue extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['document' => new DOMDocument()];

        return $this;
    }
}
