<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

use function implode;
use function sprintf;
use function str_pad;

/** Advertises $keys surrogate keys of $keyBytes bytes each */
#[CacheableResponse]
class AdvertisedKeysDonut extends ResourceObject
{
    public function onGet(int $keyBytes, int $keys = 1): static
    {
        $list = [];
        for ($i = 0; $i < $keys; $i++) {
            $list[] = str_pad(sprintf('k%d-', $i), $keyBytes, 'x');
        }

        $this->headers[Header::SURROGATE_KEY] = implode(' ', $list);
        $this->body = ['keys' => $keys];

        return $this;
    }
}
