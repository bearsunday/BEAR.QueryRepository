<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Resource\App\AdvertisedKeys;

/** @see AdvertisedKeys */
#[CacheableResponse]
class AdvertisedKeysDonut extends ResourceObject
{
    public function onGet(int $keyBytes, int $headerBytes): static
    {
        $this->headers[Header::SURROGATE_KEY] = AdvertisedKeys::header($keyBytes, $headerBytes);
        $this->body = ['headerBytes' => $headerBytes];

        return $this;
    }
}
