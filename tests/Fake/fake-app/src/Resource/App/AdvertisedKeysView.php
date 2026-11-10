<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Override;

use function implode;
use function sprintf;
use function str_pad;

/** Advertises $keys surrogate keys of $keyBytes bytes each */
#[Cacheable(type: 'view')]
class AdvertisedKeysView extends ResourceObject
{
    #[Override]
    public function toString(): string
    {
        return $this->view ??= 'view';
    }

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
