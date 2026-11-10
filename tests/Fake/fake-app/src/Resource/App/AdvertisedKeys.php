<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

use function implode;
use function min;
use function sprintf;
use function str_pad;

/** Advertises a $headerBytes-byte Surrogate-Key of $keyBytes-byte keys, the last one taking the remainder */
#[Cacheable]
class AdvertisedKeys extends ResourceObject
{
    public static function header(int $keyBytes, int $headerBytes): string
    {
        $keys = [];
        for ($i = 0, $left = $headerBytes; $left > 0; $i++) {
            $length = min($keyBytes, $left);
            $keys[] = str_pad(sprintf('k%d-', $i), $length, 'x');
            $left -= $length + 1;
        }

        return implode(' ', $keys);
    }

    public function onGet(int $keyBytes, int $headerBytes): static
    {
        $this->headers[Header::SURROGATE_KEY] = self::header($keyBytes, $headerBytes);
        $this->body = ['headerBytes' => $headerBytes];

        return $this;
    }
}
