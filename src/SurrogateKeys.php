<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function array_merge;
use function explode;
use function implode;

final class SurrogateKeys
{
    /** @var array<string> */
    private $surrogateKeys = [];

    /**
     * Add etag of embedded resource
     */
    public function addTag(ResourceObject $ro): void
    {
        if (! array_key_exists('ETag', $ro->headers)) {
            return;
        }

        $this->surrogateKeys[] = $ro->headers['ETag'];
        if (array_key_exists(CacheDependency::SURROGATE_KEY, $ro->headers)) {
            $this->surrogateKeys = array_merge($this->surrogateKeys, explode(' ', $ro->headers[CacheDependency::SURROGATE_KEY])); // @phpstan-ignore-line
        }
    }

    public function setSurrogateHeader(ResourceObject $ro): void
    {
        if ($this->surrogateKeys) {
            $ro->headers[CacheDependency::SURROGATE_KEY] = implode(' ', $this->surrogateKeys);
        }
    }
}
