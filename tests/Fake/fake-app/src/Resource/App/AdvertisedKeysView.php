<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Override;

/** @see AdvertisedKeys */
#[Cacheable(type: 'view')]
class AdvertisedKeysView extends ResourceObject
{
    #[Override]
    public function toString(): string
    {
        return $this->view ??= 'view';
    }

    public function onGet(int $keyBytes, int $headerBytes): static
    {
        $this->headers[Header::SURROGATE_KEY] = AdvertisedKeys::header($keyBytes, $headerBytes);
        $this->body = ['headerBytes' => $headerBytes];

        return $this;
    }
}
