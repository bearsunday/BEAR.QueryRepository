<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function explode;
use function http_build_query;
use function implode;
use function sprintf;
use function str_replace;

final class SurrogateKeys
{
    /** @var list<string> */
    private array $surrogateKeys;
    private UriTagInterface $uriTag;

    public function __construct(AbstractUri $uri)
    {
        $uriKey = sprintf('%s_%s', str_replace('/', '_', $uri->path), http_build_query($uri->query));
        $this->surrogateKeys = [$uriKey];
        $this->uriTag = new UriTag();
    }

    /**
     * Add etag of embedded resource
     */
    public function addTag(ResourceObject $ro): void
    {
        $this->surrogateKeys[] = ($this->uriTag)($ro->uri);
        if (array_key_exists(Header::SURROGATE_KEY, $ro->headers)) {
            $this->surrogateKeys = array_merge($this->surrogateKeys, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }
    }

    public function setSurrogateHeader(ResourceObject $ro): void
    {
        $key = implode(' ', array_unique($this->surrogateKeys));
        $wasSetManually = isset($ro->headers[Header::SURROGATE_KEY]);
        if ($wasSetManually) {
            $ro->headers[Header::SURROGATE_KEY] .= ' ' . $key;

            return;
        }

        $ro->headers[Header::SURROGATE_KEY] = $key;
    }
}
