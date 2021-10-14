<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function array_merge;
use function explode;
use function http_build_query;
use function implode;
use function sprintf;
use function str_replace;

final class SurrogateKeys
{
    /** @var array<string> */
    private $surrogateKeys;

    public function __construct(AbstractUri $uri)
    {
        $uriKey = sprintf('%s_%s', str_replace('/', '_', $uri->path), http_build_query($uri->query));
        $this->surrogateKeys = [$uriKey];
    }

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
        $ro->headers[Header::PURGE_KEYS] = implode(' ', $this->surrogateKeys);
    }
}
