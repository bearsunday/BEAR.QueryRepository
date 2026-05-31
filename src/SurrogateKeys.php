<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function explode;
use function implode;

final class SurrogateKeys
{
    /** @var list<string> */
    private array $surrogateKeys;
    private readonly UriTagInterface $uriTag;

    public function __construct(AbstractUri $uri)
    {
        $this->uriTag = new UriTag();
        $this->surrogateKeys = [($this->uriTag)($uri)];
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
        $keys = $this->surrogateKeys;
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $keys = array_merge(explode(' ', $ro->headers[Header::SURROGATE_KEY]), $keys);
        }

        $ro->headers[Header::SURROGATE_KEY] = implode(' ', array_unique($keys));
    }
}
