<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function explode;
use function implode;

final class Etags
{
    /** @var array<string> */
    private $surrogateKeys = [];

    public function addEtag(ResourceObject $ro): void
    {
        if (! array_key_exists('ETag', $ro->headers)) {
            return;
        }

        $this->surrogateKeys[] = $ro->headers['ETag'];
        if (array_key_exists(CacheDependency::SURROGATE_KEY, $ro->headers)) {
            $this->surrogateKeys[] = (string) explode('', $ro->headers[CacheDependency::SURROGATE_KEY]); // @phpstan-ignore-line
        }
    }

    public function setSurrogateKey(ResourceObject $ro): void
    {
        if ($this->surrogateKeys) {
            $ro->headers[CacheDependency::SURROGATE_KEY] = implode(' ', $this->surrogateKeys);
        }
    }
}
