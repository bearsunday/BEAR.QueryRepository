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
        if (! array_key_exists(Header::ETAG, $ro->headers)) {
            return;
        }

        $this->surrogateKeys[] = $ro->headers[Header::ETAG];
        if (array_key_exists(Header::PURGE_KEYS, $ro->headers)) {
            $this->surrogateKeys = array_merge($this->surrogateKeys, explode(' ', $ro->headers[Header::PURGE_KEYS]));
        }
    }

    public function setSurrogateHeader(ResourceObject $ro): void
    {
        if ($this->surrogateKeys) {
            $ro->headers[Header::PURGE_KEYS] = implode(' ', $this->surrogateKeys);
        }
    }
}
